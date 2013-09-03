<?php

namespace YSentry;

/**
 * A {@link CLogRoute} implementation that sends `Yii::log(..)` messages to Sentry. The component
 * {@link YSentry\ApplicationComponent} must be attached for this to work.
 *
 * @author Shiki <bj@basanes.net>
 */
class LogRoute extends \CLogRoute
{
  /**
   * The ID of the {@link YSentry\ApplicationComponent} in Yii's configuration.
   * @var string
   */
  public $componentId = 'sentry';

  /**
   * Sends all logs to Sentry.
   * @param array $logs List of log messages
   */
  protected function processLogs($logs)
  {
    $client = \Yii::app()->getComponent($this->componentId)->getClient();

    foreach ($logs as $log) {
      $message = $log[0];

      $options = array(
        'level' => self::getSentrySeverityFromLogLevel($log[1]),
        'tags' => array(
          'category' => $log[2],
        ),
      );

      $client->captureMessage($message, array(), $options);
    }
  }

  protected static function getSentrySeverityFromLogLevel($level)
  {
    switch ($level) {
      case \CLogger::LEVEL_ERROR:
        return \Raven_Client::ERROR;
      case \CLogger::LEVEL_WARNING:
        return \Raven_Client::WARNING;
      case \CLogger::LEVEL_INFO:
        return \Raven_Client::INFO;
      default:
        return \Raven_Client::DEBUG;
    }
  }
}
