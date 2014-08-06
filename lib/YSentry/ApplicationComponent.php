<?php

namespace YSentry;

/**
 * A Yii application component that provides access to pre-configured `Raven_Client` instances.
 * Configurations can be done in Yii config files. Clients can be accessed or created by:
 *
 * <code>
 * Yii::app()->sentry->getClient()
 * </code>
 *
 * To use this class, add this as a Yii component, and set the `clients` property to an array of
 * different `Raven_Client` configurations that you want to use. Different clients are set
 * with different array keys.
 *
 * Here's an example Yii configuration using this library:
 *
 * <code>
 * ...
 * 'components' => array(
 *   'raven' => array(
 *     'class' => '\\YSentry\\ApplicationComponent',
 *     'clients' => array(
 *       'default' => array(
 *         'dsn' => 'http://677e959a8a5aac9e06641f60:09ada83369852d31796273a2f@localhost:9090/2',
 *         'options' => array(
 *           'logger' => 'php',
 *           'secret_key' => '...',
 *           'public_key' => '...',
 *         ),
 *       ),
 *     ),
 *   ),
 * )
 * ...
 * </code>
 *
 * Then, access your client by:
 *
 * <code>
 * $client = \Yii::app()->sentry->getClient('default');
 * // Capture exceptions
 * $client->captureException($exception);
 * </code>
 *
 * Or, just:
 *
 * <code>
 * $client = \Yii::app()->sentry->getClient(); // Assumed "default"
 * </code>
 *
 * The above will return an instance of {@link Raven_Client} with the values of `dsn` and `options`
 * passed to the constructor. If a client with the same configuration key was previously created,
 * {@link getClient} will return the previously created client instance. If you do not want this behavior,
 * you can use {@link createClient}.
 *
 * @see https://github.com/getsentry/raven-php
 * @author Shiki <bj@basanes.net>
 */
class ApplicationComponent extends \CApplicationComponent
{
  /**
   * Client configurations. This is normally set up in Yii config files. This is an array
   * containing configurations for `Raven_Client` instances that will be created using this
   * application component. Each configuration should contain a `dsn` or an `options` key.
   * The value of those properties will be passed to the constructor of `Raven_Client`.
   *
   * Sample values:
   *
   * <code>
   * array(
   *   'default' => array(
   *     'dsn' => 'http://677e959a8a5aac9e06641f60:09ada83369852d31796273a2f@localhost:9090/2',
   *   ),
   *   'justOptions' => array(
   *     'options' => array(
   *       'secret_key' => '...',
   *       'public_key' => '...',
   *     ),
   *   ),
   *   'all' => array(
   *     'dsn' => 'http://677e959a8a5aac9e06641f60:09ada83369852d31796273a2f@localhost:9090/2',
   *     'options' => array(
   *       'secret_key' => '...',
   *       'public_key' => '...',
   *     ),
   *   ),
   * )
   * </code>
   *
   * @var array
   */
  public $clients;

  /**
   * Whether to automatically log errors, exceptions, and fatal errors. If enabled, this component
   * will register for `onException`, `onError`, and `onEndRequest` events of the current Yii
   * {@link CApplication} to log any received exceptions. Defaults to `true`.
   *
   * @var boolean
   */
  public $enableExceptionHandling = true;

  /**
   *
   * @var array
   */
  protected $_clientInstances = array();

  protected $_reservedMemory;

  /**
   * {@inheritdoc}
   */
  public function init()
  {
    if (!is_array($this->clients))
      $this->clients = array();

    if ($this->enableExceptionHandling)
      $this->registerEventHandlers();

    parent::init();
  }

  /**
   * Register event handlers to log errors, exceptions, and fatal errors.
   */
  protected function registerEventHandlers()
  {
    $app = \Yii::app();
    $app->attachEventHandler('onException', array($this, 'onExceptionEvent'));
    $app->attachEventHandler('onError', array($this, 'onErrorEvent'));

    // Fatal errors are handled differently because PHP because PHP does not call the method
    // specified by {@link set_error_handler} if PHP failed because of a FATAL (unrecoverable)
    // error. We handle fatal errors by attaching to Yii's shutdown event (`onEndRequest`) and
    // get the error from there.
    $this->reserveMemoryForShutdownHandling();
    $app->attachEventHandler('onEndRequest', array($this, 'onEndRequestEvent'));
  }

  /**
   * Common method used by the event handlers to send an exception to Sentry. You can override this
   * method for fine-grained control on what type of exceptions you want to be sent to Sentry.
   *
   * @param Exception $e
   * @param array|null $params
   */
  protected function captureException(\Exception $e, $params = null)
  {
    $this->getClient()->captureException($e, $params);
  }

  /**
   * Event handler for {@link CApplication}'s `onException` event.
   *
   * @param CExceptionEvent $event
   */
  public function onExceptionEvent(\CExceptionEvent $event)
  {
    $this->captureException($event->exception);
  }

  /**
   * Event handler for {@link CApplication}'s `onError` event.
   *
   * @param CErrorEvent $event
   */
  public function onErrorEvent(\CErrorEvent $event)
  {
    $e = new \ErrorException($event->message, $event->code, $event->code, $event->file, $event->line);

    $this->captureException($e);
  }

  /**
   * Event handler for {@link CApplication}'s `onEndRequest` event. This event handler is called
   * when the Yii application ends normally or the app will shut down because of a fatal error.
   *
   * The error handling here is based on {@link Raven_ErrorHandler}'s fatal error handler. We
   * couldn't use that class directly because we are depending on Yii's own shutdown handler.
   *
   * @param CEvent $event
   */
  public function onEndRequestEvent(\CEvent $event)
  {
    $this->detectShutdown();
    $this->getClient()->sendUnsentErrors();

    if (!($lastError = error_get_last()))
      return;

    unset($this->_reservedMemory);

    // We will only handle these error types because other types would already have been sent
    // through
    $errorTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR,
      E_COMPILE_WARNING, E_STRICT);
    if (!in_array($lastError['type'], $errorTypes))
      return;

    $e = new \ErrorException(@$lastError['message'], @$lastError['type'], @$lastError['type'],
      @$lastError['file'], @$lastError['line']);

    $this->captureException($e);
  }

  /**
   * Get an instance of `Raven_Client` using the configuration pointed to by `$key`.
   * This will store the created instance locally and subsequent calls to this method using the
   * same `$key` will return the already created client.
   *
   * @param string $key The client configuration key that can be found in {@link $clients}.
   * @return Raven_Client
   */
  public function getClient($key = 'default')
  {
    if (isset($this->_clientInstances[$key]))
      return $this->_clientInstances[$key];

    $this->_clientInstances[$key] = $this->createClient($key);
    return $this->_clientInstances[$key];
  }

  /**
   * Create an instance of `Raven_Client` using the configuration pointed to by `$key`.
   *
   * @param string $key The client configuration key that can be found in {@link $clients}.
   * @return Raven_Client
   */
  public function createClient($key = 'default')
  {
    if ($key == 'default' && !isset($this->clients[$key]))
      return new \Raven_Client();

    $config = $this->clients[$key];

    $dsn     = isset($config['dsn']) ? $config['dsn'] : null;
    $options = isset($config['options']) ? $config['options'] : array();

    return new \Raven_Client($dsn, $options);
  }

  /**
   * Taken from {@link Raven_ErrorHandler}, reserves some memory for shutdown handling.
   *
   * @param integer $size
   */
  protected function reserveMemoryForShutdownHandling($size = 10)
  {
    $this->_reservedMemory = str_repeat('x', 1024 * $size);
  }

  /**
   *  Taken from {@link Raven_ErrorHandler}, sets a shutdown flag so the client will not store
   *  errors for bulk sending. See {@link Raven_Client}.
   */
  public function detectShutdown()
  {
    if (!defined('RAVEN_CLIENT_END_REACHED')) {
      define('RAVEN_CLIENT_END_REACHED', true);
    }
  }
}

