<?php

require_once 'includes/common.inc.php';
global $redis, $config, $csrfToken, $server;

$page['css'][] = 'frame';
$page['js'][]  = 'frame';

require 'includes/header.inc.php';

if (!isset($_GET['key'])) {
  ?>
  Invalid key
  <?php

  require 'includes/footer.inc.php';
  die;
}

$type   = ''; 
$exists = false;
try {
  $type   = $redis->type($_GET['key']);
  $exists = $redis->exists($_GET['key']);
} catch (\Predis\Response\ServerException $th) {
  ?>
  <div class="exception">
    <h3><?php echo $th->getMessage() ?></h3>
  </div>
  <?php
}

$count_elements_page = isset($config['count_elements_page']) ? $config['count_elements_page'] : false;
$page_num_request    = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page_num_request    = $page_num_request === 0 ? 1 : $page_num_request;

?>
<h2><?php echo format_html($_GET['key'])?>
<?php if ($exists) { ?>
  <a href="rename.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;key=<?php echo urlencode($_GET['key'])?>"><img src="images/edit.png" width="16" height="16" title="Rename" alt="[R]"></a>
    <a href="delete.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;key=<?php echo urlencode($_GET['key'])?>" class="delkey"><img src="images/delete.png" width="16" height="16" title="Delete" alt="[X]"></a>
    <a href="export.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;key=<?php echo urlencode($_GET['key'])?>"><img src="images/export.png" width="16" height="16" title="Export" alt="[E]"></a>
<?php } ?>
</h2>
<?php

if (!$exists) {
  ?>
  This key does not exist.
  <?php

  require 'includes/footer.inc.php';
  die;
}

$alt      = false;
$ttl      = $redis->ttl($_GET['key']);

try {
  $encoding = $redis->object('encoding', $_GET['key']);
} catch (Exception $e) {
  $encoding = null;
}

switch ($type) {
  case 'string':
    $value = $redis->get($_GET['key']);
    $value = encodeOrDecode('load', $_GET['key'], $value);
    $size  = strlen($value);
    break;

  case 'hash':
    $values = $redis->hGetAll($_GET['key']);
    foreach ($values as $k => $value) {
      $values[$k] = encodeOrDecode('load', $_GET['key'], $value);
    }
    $size = count($values);
    ksort($values);
    // Optional field/value filter for hashes
    $hfilter = isset($_GET['hfilter']) ? trim($_GET['hfilter']) : '';
    if ($hfilter !== '') {
      // Syntax: "field:<pattern>" or "value:<pattern>" for substring/glob; use '=' for exact match, e.g. "field=012001"
      $mode = 'both'; // 'field' | 'value' | 'both'
      $exact = false;
      $needle = $hfilter;

      if (preg_match('/^(field|value)(:|=)(.*)$/i', $hfilter, $m)) {
        $mode = strtolower($m[1]);
        $exact = ($m[2] === '=');
        $needle = trim($m[3]);
      }

      $filtered = array();
      foreach ($values as $hkey => $val) {
        $matchField = false;
        $matchValue = false;

        // Field matching
        if ($mode === 'field' || $mode === 'both') {
          if ($exact) {
            $matchField = (strcasecmp($hkey, $needle) === 0);
          } else if (function_exists('fnmatch') && (strpos($needle, '*') !== false || strpos($needle, '?') !== false)) {
            $matchField = fnmatch($needle, $hkey, FNM_NOCASE);
          } else {
            $matchField = (stripos($hkey, $needle) !== false);
          }
        }

        // Value matching
        if ($mode === 'value' || $mode === 'both') {
          if ($exact) {
            $matchValue = (strcasecmp($val, $needle) === 0);
          } else if (function_exists('fnmatch') && (strpos($needle, '*') !== false || strpos($needle, '?') !== false)) {
            $matchValue = fnmatch($needle, $val, FNM_NOCASE);
          } else {
            $matchValue = (stripos($val, $needle) !== false);
          }
        }

        if ($matchField || $matchValue) {
          $filtered[$hkey] = $val;
        }
      }
      $values = $filtered;
      $size = count($values);
    }
    break;

  case 'list':
    // Optional value filter for lists
    $lfilter = isset($_GET['lfilter']) ? trim($_GET['lfilter']) : '';
    if ($lfilter !== '') {
      $all = $redis->lRange($_GET['key'], 0, -1);
      $values = array();
      foreach ($all as $idx => $val) {
        $val = encodeOrDecode('load', $_GET['key'], $val);
        // Matching: substring/glob/exact with prefix value= for exact, or plain input for substring
        $exact = false;
        $needle = $lfilter;
        if (preg_match('/^value=(.*)$/i', $lfilter, $m)) {
          $exact = true;
          $needle = trim($m[1]);
        }
        $matchValue = false;
        if ($exact) {
          $matchValue = (strcasecmp($val, $needle) === 0);
        } else if (function_exists('fnmatch') && (strpos($needle, '*') !== false || strpos($needle, '?') !== false)) {
          $matchValue = fnmatch($needle, $val, FNM_NOCASE);
        } else {
          $matchValue = (stripos($val, $needle) !== false);
        }
        if ($matchValue) {
          $values[$idx] = $val; // preserve original index as key
        }
      }
      $size = count($values);
    } else {
      $size = $redis->lLen($_GET['key']);
    }
    break;

  case 'set':
    $values = $redis->sMembers($_GET['key']);
    foreach ($values as $k => $value) {
      $values[$k] = encodeOrDecode('load', $_GET['key'], $value);
    }
    // Optional value filter for sets
    $sfilter = isset($_GET['sfilter']) ? trim($_GET['sfilter']) : '';
    if ($sfilter !== '') {
      // Syntax: "value:<pattern>" for substring/glob; use '=' for exact match, e.g. "value=abc"
      $exact = false;
      $needle = $sfilter;
      if (preg_match('/^value(:|=)(.*)$/i', $sfilter, $m)) {
        $exact = ($m[1] === '=');
        $needle = trim($m[2]);
      }
      $filtered = array();
      foreach ($values as $val) {
        $matchValue = false;
        if ($exact) {
          $matchValue = (strcasecmp($val, $needle) === 0);
        } else if (function_exists('fnmatch') && (strpos($needle, '*') !== false || strpos($needle, '?') !== false)) {
          $matchValue = fnmatch($needle, $val, FNM_NOCASE);
        } else {
          $matchValue = (stripos($val, $needle) !== false);
        }
        if ($matchValue) {
          $filtered[] = $val;
        }
      }
      $values = $filtered;
    }
    $size = count($values);
    sort($values);
    break;

  case 'zset':
    $values = $redis->zRange($_GET['key'], 0, -1);
    foreach ($values as $k => $value) {
      $values[$k] = encodeOrDecode('load', $_GET['key'], $value);
    }
    // Optional filter for zset (by value substring/glob/exact or score exact via score=)
    $zfilter = isset($_GET['zfilter']) ? trim($_GET['zfilter']) : '';
    if ($zfilter !== '') {
      $mode = 'value'; // 'value' or 'score'
      $exact = false;
      $needle = $zfilter;
      if (preg_match('/^(value|score)(:|=)?(.*)$/i', $zfilter, $m)) {
        $mode = strtolower($m[1]);
        $exact = ($m[2] === '=');
        $needle = trim($m[3]);
      }
      $filtered = array();
      foreach ($values as $val) {
        $score = $redis->zScore($_GET['key'], $val);
        $match = false;
        if ($mode === 'score') {
          // Only exact numeric match supported
          if ($exact) {
            $match = ((string)$score === (string)$needle);
          }
        } else { // value
          if ($exact) {
            $match = (strcasecmp($val, $needle) === 0);
          } else if (function_exists('fnmatch') && (strpos($needle, '*') !== false || strpos($needle, '?') !== false)) {
            $match = fnmatch($needle, $val, FNM_NOCASE);
          } else {
            $match = (stripos($val, $needle) !== false);
          }
        }
        if ($match) {
          $filtered[] = $val;
        }
      }
      $values = $filtered;
    }
    $size = count($values);
    break;
    
  default:
    $size = -1;
}
  
if (isset($values) && ($count_elements_page !== false)) {
  $values = array_slice($values, $count_elements_page * ($page_num_request - 1), $count_elements_page,true);
}

?>
<table>

<tr><td><div>Type:</div></td><td><div><?php echo format_html($type)?></div></td></tr>
<!-- removed i18n duplicate: keep original Type row below -->

<tr><td><div><abbr title="Time To Live">TTL</abbr>:</div></td><td><div><?php echo ($ttl == -1) ? 'does not expire' : format_ttl($ttl) ?> <a href="ttl.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;key=<?php echo urlencode($_GET['key'])?>&amp;ttl=<?php echo $ttl?>"><img src="images/edit.png" width="16" height="16" title="Edit TTL" alt="[E]" class="imgbut"></a></div></td></tr>
<!-- removed i18n duplicate TTL row -->

<?php if (!is_null($encoding)) { ?>
<tr><td><div>Encoding:</div></td><td><div><?php echo format_html($encoding)?></div></td></tr>
<?php } ?>

<tr><td><div>Size:</div></td><td><div>
<!-- removed i18n duplicate Size row -->
<?php 
echo $size;

if ($type == 'string') {
  echo " characters";
} else if ($size < 0) {
  echo " (Type Unsupported)";
} else {
  echo " items";
}
?>
</div></td></tr>

</table>

<p>
<?php


// Build pagination div.
if (($count_elements_page !== false) && in_array($type, array('hash', 'list', 'set', 'zset')) && ($size > $count_elements_page)) {
    $prev       = $page_num_request - 1;
    $next       = $page_num_request + 1;
    $lastpage   = ceil($size / $count_elements_page);
    $lpm1       = $lastpage - 1;
    $adjacents  = 3;
    $pagination = '<div style="width: inherit; word-wrap: break-word;">';
    $url        = preg_replace('/&page=(\d+)/i', '', getRelativePath('view.php'));

    if ($page_num_request > 1) $pagination .= "<a href=\"$url&page=$prev\">&#8592;</a>&nbsp;"; else
        $pagination .= "&#8592;&nbsp;";

    if ($lastpage < 7 + ($adjacents * 2)) { //not enough pages to bother breaking it up
        for ($counter = 1; $counter <= $lastpage; $counter++) {
            if ($counter == $page_num_request) $pagination .= $page_num_request . '&nbsp;'; else
                $pagination .= "<a href=\"$url&page=$counter\">$counter</a>&nbsp;";
        }
    } elseif ($lastpage > 5 + ($adjacents * 2)) { //enough pages to hide some

        if ($page_num_request < 1 + ($adjacents * 2)) { //close to beginning; only hide later pages
            for ($counter = 1; $counter < 4 + ($adjacents * 2); $counter++) {
                if ($counter == $page_num_request) $pagination .= $page_num_request . '&nbsp;'; else
                    $pagination .= "<a href=\"$url&page=$counter\">$counter</a>&nbsp;";
            }
            $pagination .= "...&nbsp;";
            $pagination .= "<a href=\"$url&page=$lpm1\">$lpm1</a>&nbsp;";
            $pagination .= "<a href=\"$url&page=$lastpage\">$lastpage</a>&nbsp;";
        } elseif ($lastpage - ($adjacents * 2) > $page_num_request && $page_num_request > ($adjacents * 2)) { //in middle; hide some front and some back
            $pagination .= "<a href=\"$url&page=1\">1</a>&nbsp;";
            $pagination .= "<a href=\"$url&page=2\">2</a>&nbsp;";
            $pagination .= "...&nbsp;";
            for ($counter = $page_num_request - $adjacents; $counter <= $page_num_request + $adjacents; $counter++) {
                if ($counter == $page_num_request) $pagination .= $page_num_request . '&nbsp;'; else
                    $pagination .= "<a href=\"$url&page=$counter\">$counter</a>&nbsp;";
            }
            $pagination .= "...&nbsp;";
            $pagination .= "<a href=\"$url&page=$lpm1\">$lpm1</a>&nbsp;";
            $pagination .= "<a href=\"$url&page=$lastpage\">$lastpage</a>&nbsp;";
        } else { //close to end; only hide early pages
            $pagination .= "<a href=\"$url&page=1\">1</a>&nbsp;";
            $pagination .= "<a href=\"$url&page=2\">2</a>&nbsp;";
            $pagination .= "...&nbsp;";
            for ($counter = $lastpage - (2 + ($adjacents * 2)); $counter <= $lastpage; $counter++) {
                if ($counter == $page_num_request) $pagination .= $page_num_request . '&nbsp;'; else
                    $pagination .= "<a href=\"$url&page=$counter\">$counter</a>&nbsp;";
            }
        }
    }
    if ($page_num_request < $counter - 1) $pagination .= "<a href=\"$url&page=$next\">&#8594;</a>&nbsp;"; else
        $pagination .= "&#8594;&nbsp;";
    $pagination .= "</div>";
}

if (isset($pagination)) {
    echo $pagination;
}


// String
if ($type == 'string') { ?>

<table>
<tr><td><div class=data><?php echo format_html($value)?></div></td><td><div>
  <a href="edit.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;type=string&amp;key=<?php echo urlencode($_GET['key'])?>"><img src="images/edit.png" width="16" height="16" title="Edit" alt="[E]"></a>
</div></td><td><div>
  <a href="delete.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;type=string&amp;key=<?php echo urlencode($_GET['key'])?>" class="delval"><img src="images/delete.png" width="16" height="16" title="Delete" alt="[X]"></a>
</div></td></tr>
</table>

<?php }



// Hash
else if ($type == 'hash') { ?>

<p>
<form method="get" action="view.php" style="margin:0;padding:0">
  <input type="hidden" name="s" value="<?php echo $server['id']?>">
  <input type="hidden" name="d" value="<?php echo $server['db']?>">
  <input type="hidden" name="key" value="<?php echo format_html($_GET['key'])?>">
  <input type="text" name="hfilter" id="hfilter" size="40" value="<?php echo isset($_GET['hfilter']) ? format_html($_GET['hfilter']) : '' ?>" placeholder="Filter: field:<p> or value:<p> (use = for exact)" class="info">
  <button type="submit">Filter</button>
  <button type="button" id="hfilter_help" title="过滤帮助" style="margin-left:.5em">?</button>
  <?php if (isset($_GET['hfilter']) && $_GET['hfilter'] !== '') { ?>
    <a href="view.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;key=<?php echo urlencode($_GET['key'])?>" class="info">clear</a>
  <?php } ?>
  </form>
</p>

<div id="hfilter_help_box" class="info" style="display:none; max-width: 640px; background:#fff; border:1px solid #ccc; padding:8px; border-radius:4px; box-shadow: 0 2px 8px rgba(0,0,0,.1);">
  <div style="font-weight:bold; margin-bottom:.25em;">过滤规则</div>
  <ul style="margin:.25em 0 .5em 1em;">
    <li>普通：不区分大小写，字段名和值都按子串匹配。</li>
    <li>只匹配字段名：field:内容</li>
    <li>只匹配字段值：value:内容</li>
    <li>精确匹配：field=内容 或 value=内容</li>
    <li>通配：支持 * 和 ?，如 field:012*</li>
  </ul>
  <div>示例：field=012001；value:*报警*</div>
  <div style="text-align:right; margin-top:.5em;"><button type="button" id="hfilter_help_close">关闭</button></div>
</div>

<script>
  (function(){
    var btn = document.getElementById('hfilter_help');
    var box = document.getElementById('hfilter_help_box');
    var closeBtn = document.getElementById('hfilter_help_close');
    if (btn && box) {
      btn.addEventListener('click', function(e){
        e.preventDefault();
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
      });
    }
    if (closeBtn && box) {
      closeBtn.addEventListener('click', function(){
        box.style.display = 'none';
      });
    }
  })();
</script>

<table>
<tr><th><div>Key</div></th><th><div>Value</div></th><th><div>&nbsp;</div></th><th><div>&nbsp;</div></th></tr>

<?php foreach ($values as $hkey => $value) { ?>
  <tr <?php echo $alt ? 'class="alt"' : ''?>><td><div><?php echo format_html($hkey)?></div></td><td><div class=data><?php echo format_html($value)?></div></td><td><div>
    <a href="edit.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;type=hash&amp;key=<?php echo urlencode($_GET['key'])?>&amp;hkey=<?php echo urlencode($hkey)?>"><img src="images/edit.png" width="16" height="16" title="Edit" alt="[E]"></a>
  </div></td><td><div>
    <a href="delete.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;type=hash&amp;key=<?php echo urlencode($_GET['key'])?>&amp;hkey=<?php echo urlencode($hkey)?>" class="delval"><img src="images/delete.png" width="16" height="16" title="Delete" alt="[X]"></a>
  </div></td></tr>
<?php $alt = !$alt; } ?>

<?php }


// List
else if ($type == 'list') { ?>

<p>
<form method="get" action="view.php" style="margin:0;padding:0">
  <input type="hidden" name="s" value="<?php echo $server['id']?>">
  <input type="hidden" name="d" value="<?php echo $server['db']?>">
  <input type="hidden" name="key" value="<?php echo format_html($_GET['key'])?>">
  <input type="text" name="lfilter" id="lfilter" size="40" value="<?php echo isset($_GET['lfilter']) ? format_html($_GET['lfilter']) : '' ?>" placeholder="Filter values (use * ?; value= for exact)" class="info">
  <button type="submit">Filter</button>
  <button type="button" id="lfilter_help" title="过滤帮助" style="margin-left:.5em">?</button>
  <?php if (isset($_GET['lfilter']) && $_GET['lfilter'] !== '') { ?>
    <a href="view.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;key=<?php echo urlencode($_GET['key'])?>" class="info">clear</a>
  <?php } ?>
  </form>
</p>

<div id="lfilter_help_box" class="info" style="display:none; max-width: 640px; background:#fff; border:1px solid #ccc; padding:8px; border-radius:4px; box-shadow: 0 2px 8px rgba(0,0,0,.1);">
  <div style="font-weight:bold; margin-bottom:.25em;">过滤规则（List 值）</div>
  <ul style="margin:.25em 0 .5em 1em;">
    <li>普通：不区分大小写，按值子串匹配。</li>
    <li>精确匹配：value=内容</li>
    <li>通配：支持 * 和 ?，如 value:*报警*</li>
  </ul>
  <div>示例：value=0；value:*D0000*</div>
  <div style="text-align:right; margin-top:.5em;"><button type="button" id="lfilter_help_close">关闭</button></div>
</div>

<script>
  (function(){
    var btn = document.getElementById('lfilter_help');
    var box = document.getElementById('lfilter_help_box');
    var closeBtn = document.getElementById('lfilter_help_close');
    if (btn && box) {
      btn.addEventListener('click', function(e){
        e.preventDefault();
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
      });
    }
    if (closeBtn && box) {
      closeBtn.addEventListener('click', function(){
        box.style.display = 'none';
      });
    }
  })();
</script>

<table>
<tr><th><div>Index</div></th><th><div>Value</div></th><th><div>&nbsp;</div></th><th><div>&nbsp;</div></th></tr>

<?php 
  if (isset($values)) {
    // Filtered mode: iterate over prepared values preserving original index keys
    foreach ($values as $i => $value) {
?>
  <tr <?php echo $alt ? 'class="alt"' : ''?>><td><div><?php echo $i?></div></td><td><div class=data><?php echo format_html($value)?></div></td><td><div>
    <a href="edit.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;type=list&amp;key=<?php echo urlencode($_GET['key'])?>&amp;index=<?php echo $i?>"><img src="images/edit.png" width="16" height="16" title="Edit" alt="[E]"></a>
  </div></td><td><div>
    <a href="delete.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;type=list&amp;key=<?php echo urlencode($_GET['key'])?>&amp;index=<?php echo $i?>" class="delval"><img src="images/delete.png" width="16" height="16" title="Delete" alt="[X]"></a>
  </div></td></tr>
<?php $alt = !$alt; }
  } else {
    // Unfiltered mode: lazy load per index with pagination
    if (($count_elements_page === false) && ($size > $count_elements_page)) {
      $start = 0;
      $end   = $size;
    } else {
      $start = $count_elements_page * ($page_num_request - 1);
      $end   = min($start + $count_elements_page, $size);
    }
    for ($i = $start; $i < $end; ++$i) {
      $value = $redis->lIndex($_GET['key'], $i);
      $value = encodeOrDecode('load', $_GET['key'], $value);
?>
  <tr <?php echo $alt ? 'class="alt"' : ''?>><td><div><?php echo $i?></div></td><td><div class=data><?php echo format_html($value)?></div></td><td><div>
    <a href="edit.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;type=list&amp;key=<?php echo urlencode($_GET['key'])?>&amp;index=<?php echo $i?>"><img src="images/edit.png" width="16" height="16" title="Edit" alt="[E]"></a>
  </div></td><td><div>
    <a href="delete.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;type=list&amp;key=<?php echo urlencode($_GET['key'])?>&amp;index=<?php echo $i?>" class="delval"><img src="images/delete.png" width="16" height="16" title="Delete" alt="[X]"></a>
  </div></td></tr>
<?php $alt = !$alt; }
  } ?>

<?php }



// Set
else if ($type == 'set') {

?>
<p>
<form method="get" action="view.php" style="margin:0;padding:0">
  <input type="hidden" name="s" value="<?php echo $server['id']?>">
  <input type="hidden" name="d" value="<?php echo $server['db']?>">
  <input type="hidden" name="key" value="<?php echo format_html($_GET['key'])?>">
  <input type="text" name="sfilter" id="sfilter" size="40" value="<?php echo isset($_GET['sfilter']) ? format_html($_GET['sfilter']) : '' ?>" placeholder="Filter values (use * ?; value= for exact)" class="info">
  <button type="submit">Filter</button>
  <button type="button" id="sfilter_help" title="过滤帮助" style="margin-left:.5em">?</button>
  <?php if (isset($_GET['sfilter']) && $_GET['sfilter'] !== '') { ?>
    <a href="view.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;key=<?php echo urlencode($_GET['key'])?>" class="info">clear</a>
  <?php } ?>
  </form>
</p>

<div id="sfilter_help_box" class="info" style="display:none; max-width: 640px; background:#fff; border:1px solid #ccc; padding:8px; border-radius:4px; box-shadow: 0 2px 8px rgba(0,0,0,.1);">
  <div style="font-weight:bold; margin-bottom:.25em;">过滤规则（Set 值）</div>
  <ul style="margin:.25em 0 .5em 1em;">
    <li>普通：不区分大小写，按值子串匹配。</li>
    <li>精确匹配：value=内容</li>
    <li>通配：支持 * 和 ?，如 value:*报警*</li>
  </ul>
  <div>示例：value=0；value:*D0000*</div>
  <div style="text-align:right; margin-top:.5em;"><button type="button" id="sfilter_help_close">关闭</button></div>
</div>

<script>
  (function(){
    var btn = document.getElementById('sfilter_help');
    var box = document.getElementById('sfilter_help_box');
    var closeBtn = document.getElementById('sfilter_help_close');
    if (btn && box) {
      btn.addEventListener('click', function(e){
        e.preventDefault();
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
      });
    }
    if (closeBtn && box) {
      closeBtn.addEventListener('click', function(){
        box.style.display = 'none';
      });
    }
  })();
</script>
<table>
<tr><th><div>Value</div></th><th><div>&nbsp;</div></th><th><div>&nbsp;</div></th></tr>

<?php foreach ($values as $value) {
  $display_value = $redis->exists($value) ? '<a href="view.php?s='.$server['id'].'&d='.$server['db'].'&key='.urlencode($value).'">'.format_html($value).'</a>' : format_html($value);
?>
  <tr <?php echo $alt ? 'class="alt"' : ''?>><td><div class=data><?php echo $display_value ?></div></td><td><div>
    <a href="edit.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;type=set&amp;key=<?php echo urlencode($_GET['key'])?>&amp;value=<?php echo urlencode($value)?>"><img src="images/edit.png" width="16" height="16" title="Edit" alt="[E]"></a>
  </div></td><td><div>
    <a href="delete.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;type=set&amp;key=<?php echo urlencode($_GET['key'])?>&amp;value=<?php echo urlencode($value)?>" class="delval"><img src="images/delete.png" width="16" height="16" title="Delete" alt="[X]"></a>
  </div></td></tr>
<?php $alt = !$alt; } ?>

<?php }



// ZSet
else if ($type == 'zset') { ?>

<p>
<form method="get" action="view.php" style="margin:0;padding:0">
  <input type="hidden" name="s" value="<?php echo $server['id']?>">
  <input type="hidden" name="d" value="<?php echo $server['db']?>">
  <input type="hidden" name="key" value="<?php echo format_html($_GET['key'])?>">
  <input type="text" name="zfilter" id="zfilter" size="40" value="<?php echo isset($_GET['zfilter']) ? format_html($_GET['zfilter']) : '' ?>" placeholder="Filter: value substring/glob, score=exact" class="info">
  <button type="submit">Filter</button>
  <button type="button" id="zfilter_help" title="过滤帮助" style="margin-left:.5em">?</button>
  <?php if (isset($_GET['zfilter']) && $_GET['zfilter'] !== '') { ?>
    <a href="view.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;key=<?php echo urlencode($_GET['key'])?>" class="info">clear</a>
  <?php } ?>
  </form>
</p>

<div id="zfilter_help_box" class="info" style="display:none; max-width: 640px; background:#fff; border:1px solid #ccc; padding:8px; border-radius:4px; box-shadow: 0 2px 8px rgba(0,0,0,.1);">
  <div style="font-weight:bold; margin-bottom:.25em;">过滤规则（ZSet）</div>
  <ul style="margin:.25em 0 .5em 1em;">
    <li>按值过滤：不区分大小写，支持通配 `*`、`?`，或精确 `value=`。</li>
    <li>按分数过滤：仅支持精确匹配，使用 `score=数字`。</li>
  </ul>
  <div>示例：value:*报警*；score=10</div>
  <div style="text-align:right; margin-top:.5em;"><button type="button" id="zfilter_help_close">关闭</button></div>
</div>

<script>
  (function(){
    var btn = document.getElementById('zfilter_help');
    var box = document.getElementById('zfilter_help_box');
    var closeBtn = document.getElementById('zfilter_help_close');
    if (btn && box) {
      btn.addEventListener('click', function(e){
        e.preventDefault();
        box.style.display = (box.style.display === 'none' || box.style.display === '') ? 'block' : 'none';
      });
    }
    if (closeBtn && box) {
      closeBtn.addEventListener('click', function(){
        box.style.display = 'none';
      });
    }
  })();
</script>

<table>
<tr><th><div>Score</div></th><th><div>Value</div></th><th><div>&nbsp;</div></th><th><div>&nbsp;</div></th></tr>

<?php foreach ($values as $value) {
  $score         = $redis->zScore($_GET['key'], $value);
  $display_value = $redis->exists($value) ? '<a href="view.php?s='.$server['id'].'&d='.$server['db'].'&key='.urlencode($value).'">'.format_html($value).'</a>' : format_html($value);
?>
  <tr <?php echo $alt ? 'class="alt"' : ''?>><td><div><?php echo $score?></div></td><td><div class=data><?php echo $display_value ?></div></td><td><div>
    <a href="edit.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;type=zset&amp;key=<?php echo urlencode($_GET['key'])?>&amp;score=<?php echo $score?>&amp;value=<?php echo urlencode($value)?>"><img src="images/edit.png" width="16" height="16" title="Edit" alt="[E]"></a>
    <a href="delete.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;type=zset&amp;key=<?php echo urlencode($_GET['key'])?>&amp;value=<?php echo urlencode($value)?>" class="delval"><img src="images/delete.png" width="16" height="16" title="Delete" alt="[X]"></a>
  </div></td></tr>
<?php $alt = !$alt; } ?>

<?php }

if ($type != 'string') { ?>
  </table>

  <p>
  <a href="edit.php?s=<?php echo $server['id']?>&amp;d=<?php echo $server['db']?>&amp;type=<?php echo $type?>&amp;key=<?php echo urlencode($_GET['key'])?>" class="add">Add another value</a>
  </p>
<?php }

if (isset($pagination)) {
  echo $pagination;
}

require 'includes/footer.inc.php';
