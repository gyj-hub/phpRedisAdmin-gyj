<?php

require_once 'includes/common.inc.php';
global $redis, $config, $csrfToken, $server;

$info = array();

foreach ($config['servers'] as $i => $server) {
  if (!isset($server['db'])) {
      $server['db'] = 0;
  }


  if (isset($config['login_as_acl_auth'])) {
    // Currently only support one server at a time
    if ($i > 0) {
      break;
    }
  } else {
    // Setup a connection to Redis.
    if (isset($server['cluster']) && $server['cluster']) {
      // Redis Cluster mode
      $clusterNodes = array();
      
      // Add the main server node
      $scheme = isset($server['scheme']) ? $server['scheme'] : 'tcp';
      $clusterNodes[] = $scheme.'://'.$server['host'].':'.$server['port'];
      
      // Add additional cluster nodes if specified
      if (isset($server['cluster_nodes']) && is_array($server['cluster_nodes'])) {
        foreach ($server['cluster_nodes'] as $node) {
          $nodeScheme = isset($node['scheme']) ? $node['scheme'] : $scheme;
          $clusterNodes[] = $nodeScheme.'://'.$node['host'].':'.$node['port'];
        }
      }
      
      $options = array('cluster' => 'redis');
      
      // Add authentication if configured
      if (isset($server['auth'])) {
        $options['parameters'] = array('password' => $server['auth']);
      }
      
      try {
        $redis = new Predis\Client($clusterNodes, $options);
        $redis->connect();
      } catch (Predis\CommunicationException $exception) {
        $redis = false;
      }
    } else {
      // Standard single-server mode
      if(isset($server['scheme']) && $server['scheme'] === 'unix' && $server['path']) {
        $redis = new Predis\Client(array('scheme' => 'unix', 'path' => $server['path']));
      } else {
        $redis = !$server['port'] ? new Predis\Client($server['host']) : new Predis\Client('tcp://'.$server['host'].':'.$server['port']);
      }
      try {
        $redis->connect();
      } catch (Predis\CommunicationException $exception) {
        $redis = false;
      }
    }
  }

  if(!$redis) {
      $info[$i] = false;
  } else {
      // In cluster mode, authentication is handled during client creation
      // Do not call auth() method separately
      if (!isset($server['cluster']) || !$server['cluster']) {
        if (isset($server['auth'])) {
          if (!$redis->auth($server['auth'])) {
            die('ERROR: Authentication failed ('.$server['host'].':'.$server['port'].')');
          }
        }
      }
      
      // Cluster mode only supports database 0
      if ($server['db'] != 0 && (!isset($server['cluster']) || !$server['cluster'])) {
        if (!$redis->select($server['db'])) {
          die('ERROR: Selecting database failed ('.$server['host'].':'.$server['port'].','.$server['db'].')');
        }
      }

      // In cluster mode, INFO command may not work as expected
      try {
        if (isset($server['cluster']) && $server['cluster']) {
          // Try to get basic info from cluster
          $info[$i] = $redis->executeRaw(['INFO']);
          if (is_string($info[$i])) {
            $parsed = array();
            $lines = explode("\r\n", $info[$i]);
            foreach ($lines as $line) {
              $line = trim($line);
              if (empty($line) || $line[0] === '#') continue;
              $parts = explode(':', $line, 2);
              if (count($parts) === 2) {
                $parsed[$parts[0]] = $parts[1];
              }
            }
            $info[$i] = $parsed;
          }
        } else {
          $info[$i] = $redis->info();
        }
      } catch (Exception $e) {
        $info[$i] = array('error' => 'Could not retrieve info');
      }
      
      // DBSIZE command handling for cluster mode
      if (isset($server['cluster']) && $server['cluster']) {
        // In cluster mode, we need to get size from all nodes
        try {
          $clusterSlots = $redis->executeRaw(['CLUSTER', 'SLOTS']);
          $masterNodes = array();
          foreach ($clusterSlots as $slot) {
            if (isset($slot[2])) {
              $nodeKey = $slot[2][0] . ':' . $slot[2][1];
              $masterNodes[$nodeKey] = array('host' => $slot[2][0], 'port' => $slot[2][1]);
            }
          }
          
          $totalSize = 0;
          foreach ($masterNodes as $node) {
            try {
              $nodeClient = new Predis\Client(array(
                'scheme' => $server['scheme'],
                'host'   => $node['host'],
                'port'   => $node['port'],
                'password' => isset($server['auth']) ? $server['auth'] : null,
              ));
              $totalSize += $nodeClient->dbSize();
              $nodeClient->disconnect();
            } catch (Exception $e) {
              error_log("Failed to get dbSize from node {$node['host']}:{$node['port']}: " . $e->getMessage());
            }
          }
          $info[$i]['size'] = $totalSize;
        } catch (Exception $e) {
          $info[$i]['size'] = 0;
          error_log("Failed to get cluster size: " . $e->getMessage());
        }
      } else {
        try {
          $info[$i]['size'] = $redis->dbSize();
        } catch (Exception $e) {
          $info[$i]['size'] = 0;
        }
      }
      
      if (isset($config['login_as_acl_auth'])) {
        try {
          $info[$i]['username'] = $redis->acl->whoami();
        } catch (Exception $e) {
          // ACL not supported or error
        }
      }

      if (!isset($info[$i]['Server'])) {
        $info[$i]['Server'] = array(
          'redis_version'     => $info[$i]['redis_version'],
          'uptime_in_seconds' => $info[$i]['uptime_in_seconds']
        );
      }
      if (!isset($info[$i]['Memory'])) {
        $info[$i]['Memory'] = array(
          'used_memory' => $info[$i]['used_memory']
        );
      }
  }


}




$page['css'][] = 'frame';
$page['js'][]  = 'frame';

require 'includes/header.inc.php';

?>

<?php foreach ($config['servers'] as $i => $server) { ?>
  <div class="server">
  <h2><?php echo isset($server['name']) ? format_html($server['name']) : format_html($server['host'])?></h2>

  <?php if(!$info[$i]): ?>
  <div style="text-align:center;color:red">Server Down</div>
  <?php else: ?>

  <table>

  <tr><td><div>Redis version:</div></td><td><div><?php echo $info[$i]['Server']['redis_version']?></div></td></tr>

  <tr><td><div>Keys:</div></td><td><div><?php echo $info[$i]['size']?></div></td></tr>

  <tr><td><div>Memory used:</div></td><td><div><?php echo format_size($info[$i]['Memory']['used_memory'])?></div></td></tr>

  <tr><td><div>Uptime:</div></td><td><div><?php echo format_time($info[$i]['Server']['uptime_in_seconds'])?></div></td></tr>

  <?php if(isset($info[$i]['username'])): ?>
  <tr><td><div>Username:</div></td><td><div><?php echo ($info[$i]['username'])?></div></td></tr>
  <?php endif ?>

  <tr><td><div>Last save:</div></td><td><div>
    <?php 
        if (isset($info[$i]['Persistence']['rdb_last_save_time'])) {
           if((time() - $info[$i]['Persistence']['rdb_last_save_time'] ) >= 0) {
              echo format_time(time() - $info[$i]['Persistence']['rdb_last_save_time']) . " ago";
           } else { 
              echo format_time(-(time() - $info[$i]['Persistence']['rdb_last_save_time'])) . "in the future"; 
           } 
        } else { 
           echo 'never';
        } 
    ?> 
    <a href="save.php?s=<?php echo $i?>"><img src="images/save.png" width="16" height="16" title="Save Now" alt="[S]" class="imgbut"></a></div></td></tr>

  </table>
  <?php endif; ?>
  </div>
<?php } ?>

<p class="clear">
<a href="https://github.com/ErikDubbelboer/phpRedisAdmin" target="_blank">phpRedisAdmin on GitHub</a>
</p>

<p>
<a href="https://redis.io/documentation" target="_blank">Redis Documentation</a>
</p>
<?php

require 'includes/footer.inc.php';

?>
