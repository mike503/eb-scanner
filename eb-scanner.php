#!/usr/bin/env php
<?php
require 'vendor/autoload.php';
require 'config.php';

if (!aws_check()) {
  _echo('ERROR: COULD NOT CREATE AWS API OBJECT. CANNOT CONTINUE.');
  exit(255);
}
$client = $GLOBALS['aws']->createElasticBeanstalk();

// build list of available solution stacks/platforms
$categories = array('PHP', 'Node.js');
foreach ($categories as $category) {
  $platforms[$category] = array();
  try {
    $result = $client->listPlatformVersions(array(
      'Filters' => array(
        array(
          'Type' => 'PlatformName',
          'Operator' => 'contains',
          'Values' => [$category],
        ),
        array(
          'Type' => 'PlatformStatus',
          'Operator' => '=',
          'Values' => ['Ready'],
        ),
      ),
    ));
    foreach ($result['PlatformSummaryList'] as $platform) {
      $platforms[$category][] = $platform['PlatformArn'];
    }
  }
  catch (Exception $e) {
    _echo('Exception: ' . $e->getMessage());
    exit(255);
  }
  rsort($platforms[$category]);
  $latest[$category] = $platforms[$category][0];
}

// build list of existing environments
try {
  $result = $client->describeEnvironments(array(
    'IncludeDeleted' => false,
  ));
  foreach ($result['Environments'] as $environment) {
    if ($environment['Status'] == 'Ready') {
      $environments[] = array(
        'id' => $environment['EnvironmentId'],
        'name' => $environment['EnvironmentName'],
        'platform' => $environment['PlatformArn'],
      );
    }
  }
}
catch (Exception $e) {
  _echo('Exception: ' . $e->getMessage());
  exit(255);
}

foreach ($environments as $environment) {
  if (strstr($environment['platform'], 'PHP')) {
    if ($environment['platform'] != $latest['PHP']) {
      if (!_update_environment($environment, $latest['PHP'])) {
        _echo('ERROR: Environment update failed');
      }
    }
  }
  elseif (strstr($environment['platform'], 'Node.js')) {
    if ($environment['platform'] != $latest['Node.js']) {
      if (!_update_environment($environment, $latest['Node.js'])) {
        _echo('ERROR: Environment update failed');
      }
    }
  }
  else {
    _echo('ERROR: unmapped environment type! ' . $environment['platform']);
  }
}

###

function _update_environment($environment = array(), $arn = '') {
  _echo('UPDATE AVAILABLE: ' . $environment['name'] . ' to ' . $arn);
  if (!isset($_SERVER['argv'][1]) || $_SERVER['argv'][1] != 'yes') {
    return TRUE;
  }
  $client = $GLOBALS['aws']->createElasticBeanstalk();
  _echo('Updating ' . $environment['name'] . ' to ' . $arn);
  try {
    $result = $client->updateEnvironment(array(
      'EnvironmentId' => $environment['id'],
      'PlatformArn' => $arn,
    ));
    if (isset($result['@metadata']['statusCode']) && $result['@metadata']['statusCode'] == 200) {
      _echo('Successfully queued environment update');
      return TRUE;
    }    
  }
  catch (Exception $e) {
    _echo('Exception: ' . $e->getMessage());
    return FALSE;
  }
  return FALSE;
}

function aws_check() {
  if (isset($GLOBALS['aws'])) {
    return TRUE;
  }
  elseif (aws_init()) {
    return TRUE;
  }
  return FALSE;
}

function aws_init() {
  if (!isset($GLOBALS['config']['aws_key']) || !isset($GLOBALS['config']['aws_secret']) || !isset($GLOBALS['config']['aws_region'])) {
    _echo('ERROR: missing AWS API information in $config.');
    exit;
  }
  $aws = new Aws\Sdk(array(
    'region' => $GLOBALS['config']['aws_region'],
    'version' => 'latest',
    'credentials' => array(
      'key' => $GLOBALS['config']['aws_key'],
      'secret' => $GLOBALS['config']['aws_secret'],
    ),
  ));
// @TODO - try/catch?
  $GLOBALS['aws'] = $aws;
  return TRUE;
}

function _echo($string = '') {
  echo '[' . date('r') . '] ' . $string . PHP_EOL;
}
