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
   *
   * @var array
   */
  protected $_clientInstances = array();

  /**
   * {@inheritdoc}
   */
  public function init()
  {
    if (!is_array($this->clients))
      $this->clients = array();

    parent::init();
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
}

