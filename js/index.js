$(function() {
  $('#selected_all_keys').on('click', function () {
    if ($(this).html()=='Select all'){
      $('input[name=checked_keys]').each(function () {
        $(this).attr('checked', 'checked');
      });
      $(this).html('Select none');
    }else {
      $('input[name=checked_keys]').each(function () {
        $(this).removeAttr('checked');
      });
      $(this).html('Select all');
    }
  })

  $('#sidebar').on('click', 'a', function(e) {
    if (e.currentTarget.className.indexOf('batch_del') !== -1) {
      e.preventDefault();
      var selected_keys = [];
      $('input[name=checked_keys]:checked').each(function () {
        selected_keys.push($(this).val());
      });
      if (selected_keys.length == 0) {
        alert('Please select the keys you want to delete.');
        return;
      }
      if (confirm('Are you sure you want to delete all selected keys?')) {
        $.ajax({
          type: "POST",
          url: this.href,
          data: {
            post: 1,
            selected_keys: JSON.stringify(selected_keys),
            csrf: phpRedisAdmin_csrfToken
          },
          success: function(url) {
            top.location.href = top.location.pathname+url;
          }
        });
      }
    } else if (e.currentTarget.className.indexOf('deltree') !== -1) {
      e.preventDefault();

      if (confirm('Are you sure you want to delete this whole tree and all it\'s keys?')) {
        $.ajax({
          type: "POST",
          url: this.href,
          data: {
            post: 1,
            csrf: phpRedisAdmin_csrfToken
          },
          success: function(url) {
            top.location.href = top.location.pathname+url;
          }
        });
      }
    } else {
      if (e.currentTarget.href.indexOf('/?') == -1) {
        return;
      }

      e.preventDefault();

      var href;

      if ((e.currentTarget.href.indexOf('?') == -1) ||
          (e.currentTarget.href.indexOf('?') == (e.currentTarget.href.length - 1))) {
        href = 'overview.php';
      } else {
        href = e.currentTarget.href.substr(e.currentTarget.href.indexOf('?') + 1);

        if (href.indexOf('&') != -1) {
          href = href.replace('&', '.php?');
        } else {
          href += '.php';
        }
      }

      if (href.indexOf('flush.php') == 0) {
        if (confirm('Are you sure you want to delete this key and all it\'s values?')) {
          $.ajax({
            type: "POST",
            url: href,
            data: {
              post: 1,
              csrf: phpRedisAdmin_csrfToken
            },
            success: function() {
              window.location.reload();
            }
          });
        }
      } else {
        $('#iframe').attr('src', href);
      }

      $('li.current').removeClass('current');
      $(this).parent().addClass('current');
    }
  });

  $('#server').change(function(e) {
    // always show overview when switching server, only keep var s (old database index might not exist on new server)
    const base = location.href.split('?', 1)[0];
    location.href = base + '?overview&s=' + e.target.value;
  });


  $('#database').change(function(e) {
    // always show overview when switching db, only keep vars s and d (whatever we are doing (show/edit key) won't be valid on new db)
    const base = location.href.split('?', 1)[0];
    const s = location.href.match(/s=[0-9]*/);
    location.href = base + '?overview&' + s + '&d=' + e.target.value;
  });


  $('li.current').parents('li.folder').removeClass('collapsed');

  $('#sidebar').on('click', 'li.folder', function(e) {
    var t = $(this);

    if ((e.pageY >= t.offset().top) &&
        (e.pageY <= t.offset().top + t.children('div').height())) {
      e.stopPropagation();
      t.toggleClass('collapsed');
    }
  });

  $('#btn_server_filter').click(function() {
    var filter = $('#server_filter').val();
    location.href = top.location.pathname + '?overview&s=' + $('#server').val() + '&d=' + ($('#database').val() || '') + '&filter=' + filter;
  });

  $('#server_filter').keydown(function(e){
    if (e.keyCode == 13) {
      $('#btn_server_filter').click();
    }
  });

  // 防抖函数，避免频繁触发过滤
  var debounce = function(func, wait) {
    var timeout;
    return function() {
      var context = this, args = arguments;
      clearTimeout(timeout);
      timeout = setTimeout(function() {
        func.apply(context, args);
      }, wait);
    };
  };

  // 缓存元素引用
  var $keyItems = null;
  var $folderItems = null;
  
  var filterKeys = function() {
    var val = $('#filter').val().toLowerCase(); // 转小写以支持不区分大小写的搜索
    
    // 如果是初次过滤，缓存元素
    if (!$keyItems) {
      $keyItems = $('li:not(.folder)');
      $folderItems = $('li.folder');
    }
    
    // 使用 DocumentFragment 来批量更新 DOM
    var fragment = document.createDocumentFragment();
    
    // 过滤键
    $keyItems.each(function(i, el) {
      var $el = $(el);
      var anchor = $('a', el).get(0);
      if (!anchor) return;
      
      var href = anchor.href;
      var keyIndex = href.indexOf('key=');
      if (keyIndex === -1) return;
      
      var key = unescape(href.substr(keyIndex + 4)).toLowerCase();
      
      // 使用 toggle 代替 addClass/removeClass
      $el.toggleClass('hidden', key.indexOf(val) === -1);
    });
    
    // 过滤文件夹（延迟执行，避免重复计算）
    requestAnimationFrame(function() {
      $folderItems.each(function(i, el) {
        var $el = $(el);
        var hasVisibleChildren = $('li:not(.hidden, .folder)', el).length > 0;
        $el.toggleClass('hidden', !hasVisibleChildren);
      });
    });
  };

  $('#filter').focus(function() {
    if ($(this).hasClass('info')) {
      $(this).removeClass('info').val('');
    }
  }).on('input', debounce(filterKeys, 150)); // 使用 input 事件和防抖

  var isResizing = false;
  var lastDownX  = 0;
  var lastWidth  = 0;

  var resizeSidebar = function(w) {
    $('#sidebar').css('width', w);
    $('#keys').css('width', w);
    $('#resize').css('left', w + 10);
    $('#resize-layover').css('left', w + 15);
    $('#frame').css('left', w + 15);
  };

  if (parseInt($.cookie('sidebar')) > 0) {
    resizeSidebar(parseInt($.cookie('sidebar')));
  }

  $('#resize').on('mousedown', function (e) {
    isResizing = true;
    lastDownX  = e.clientX;
    lastWidth  = $('#sidebar').width();
    $('#resize-layover').css('z-index', 1000);
    e.preventDefault();
  });
  $(document).on('mousemove', function (e) {
    if (!isResizing) {
      return;
    }

    var w = lastWidth - (lastDownX - e.clientX);
    if (w < 250 ) {
      w = 250;
    } else if (w > 1000) {
      w = 1000;
    }

    resizeSidebar(w);
    $.cookie('sidebar', w);
  }).on('mouseup', function (e) {
    isResizing = false;
    $('#resize-layover').css('z-index', 0);
  });
});

