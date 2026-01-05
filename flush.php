<?php

if (!isset($_POST['post'])) {
  die('Javascript needs to be enabled for you to flush a database.');
}

require_once 'includes/common.inc.php';
global $redis, $config, $csrfToken, $server;

// FLUSHDB command handling for cluster mode
if (isset($server['cluster']) && $server['cluster']) {
  // In cluster mode, we need to flush all nodes
  try {
    // Use FLUSHALL which works on clusters (flushes all databases on all nodes)
    $redis->executeRaw(['FLUSHALL']);
  } catch (Exception $e) {
    // If that fails, try to flush each node individually
    try {
      $clusterSlots = $redis->executeRaw(['CLUSTER', 'SLOTS']);
      $masterNodes = array();
      foreach ($clusterSlots as $slot) {
        if (isset($slot[2])) {
          $nodeKey = $slot[2][0] . ':' . $slot[2][1];
          $masterNodes[$nodeKey] = array('host' => $slot[2][0], 'port' => $slot[2][1]);
        }
      }
      
      foreach ($masterNodes as $node) {
        try {
          $nodeClient = new Predis\Client(array(
            'scheme' => $server['scheme'],
            'host'   => $node['host'],
            'port'   => $node['port'],
            'password' => isset($server['auth']) ? $server['auth'] : null,
          ));
          $nodeClient->flushdb();
          $nodeClient->disconnect();
        } catch (Exception $e) {
          error_log("Failed to flush node {$node['host']}:{$node['port']}: " . $e->getMessage());
        }
      }
    } catch (Exception $e2) {
      error_log("Failed to flush cluster: " . $e2->getMessage());
    }
  }
} else {
  $redis->flushdb();
}

