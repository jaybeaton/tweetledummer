let twtldDebug = false;
let twtldUsername = '';
let twtldListname= '';
const SCROLL_OFFSET = 65;

(function ($) {

  let numToKeep = 30;
  let noNewTweets = false;
  let lastTweetID = '';

  let checkingLoadMore = false;
  let isLoading = false;

  if (window.location.hash === '#debug') {
    twtldDebug = true;
  }
  else if (window.location.hash) {
    let hash = window.location.hash.substring(1);
    let parts = hash.split(':');
    if (parts[0] === 'list') {
      twtldListname = parts[1];
    }
    else {
      twtldUsername = hash;
    }
  }

  let callAjax = function (url, callback) {
    let xmlhttp = new XMLHttpRequest();
    xmlhttp.onreadystatechange = function () {
      if (xmlhttp.readyState === xmlhttp.DONE) {
        if (xmlhttp.status === 200) {
          callback(xmlhttp.responseText, xmlhttp.status);
        }
        else {
          callback('', xmlhttp.status);
        }
      }
    };
    if (twtldDebug) {
      console.log('callAjax() : url=' + url);
    }
    xmlhttp.open('GET', url, true);
    xmlhttp.send();
  };

  let processLoadMoreButton = function (button) {

    if (twtldDebug) {
      console.log('processLoadMoreButton() Called.');
    }

    if (isLoading) {
      if (twtldDebug) {
        console.log('Already loading more content.');
      }
      return;
    }
    isLoading = true;

    $('.loading-message').remove();
    let message = '<div class="loading-message" role="alert">Loading...</div>';
    $('#load-more').before(message);

    $(button).addClass('loading');
    $('#unread-count').addClass('loading');

    let lastID = $('.tweetledum-tweet').last().attr('data-id');
    if (typeof lastID === 'undefined') {
      if (twtldDebug) {
        console.log('processLoadMoreButton() No last tweet found.');
      }
      lastID = 0;
    }

    let url = 'ajax.php?id=' + lastID + '&t=' + Date.now();
    if (twtldUsername) {
      url += '&author=' + encodeURI(twtldUsername);
      $('#current-view').text('@' + twtldUsername);
      // $('#current-view').text('@' + twtldUsername.replace('.bsky.social', ''));
    }
    else if (twtldListname) {
      url += '&list=' + encodeURI(twtldListname);
      $('#current-view').text('#' + twtldListname);
    }
    else {
      $('#current-view').parent().remove();
    }

    if (twtldDebug) {
      console.log('processLoadMoreButton() Will make Ajax call to url: ' + url);
    }
    callAjax(url, function (content, status) {

      isLoading = false;
      if (!content) {
        if (twtldDebug) {
          if (status === 200) {
            console.log('processLoadMoreButton() No new content found.');
          }
          else {
            console.log('processLoadMoreButton() Error reading tweets.');
          }
        }
        noNewTweets = true;
        if (status === 200) {
          $('.loading-message').text('No new posts.');
        }
        else {
          $('.loading-message').addClass('error').text('Error reading tweets.');
        }
        setTimeout(function () {
          $('.loading-message').fadeOut(500, function () {
            $(this).remove();
          });
        }, 3000);
        $(button).removeClass('loading');
        $('#unread-count').removeClass('loading');
        return;
      }

      if (twtldDebug) {
        console.log('processLoadMoreButton() Found new content.');
      }
      setTimeout(function () {
        $('.loading-message').fadeOut(500, function () {
          $(this).remove();
        });
      }, 1000);

      noNewTweets = false;

      $('.tweetledum-feed').append(content);
      setTimeout(function () {
        scan(document);
        // twttr.widgets.load();
      }, 1000);

      let tweets = $('.tweetledum-tweet');
      let totalTweets = tweets.length;
      let existing = tweets.not('.tweetledum-new');
      $('.tweetledum-tweet.tweetledum-new').click(function () {
        $('.active').removeClass('active');
        markActive($(this));
      }).removeClass('tweetledum-new');

      if (totalTweets > numToKeep) {
        let n = 0;
        let numToDelete = totalTweets - numToKeep;
        existing.each(function () {
          if (n < numToDelete) {
            $(this).remove();
            n++;
          }
        });
      }

      $(button).removeClass('loading');
      $('#unread-count').removeClass('loading');

      if ($('.active').length === 0) {
        markActive($('.tweetledum-tweet').first());
      }

    });

  };

  let markActive = function (tweet) {
    if (twtldDebug) {
      console.log('markActive() called on tweet:');
      console.log(tweet);
    }
    $(tweet).addClass('active');
    let id = $(tweet).prev().attr('data-id');
    if (id) {
      lastTweetID = id;
    }
    let blockquotes = $(tweet).find('blockquote');
    if (blockquotes.length > 0) {
      // Tweet embed wasn't loaded.
      blockquotes.each( function() {
        console.log('Reloading embed.');
        let blockquote = $(this);
        $.each(this.attributes,function(i,a) {
          if (a.name.indexOf('data-twitter-extracted') === 0) {
            blockquote.removeAttr(a.name);
            scan(document);
            // twttr.widgets.load($(tweet)[0]);
          }
        });
      });
    }
    if (typeof id === 'undefined') {
      // No previous tweet.
      id = 0;
      if (twtldDebug) {
        console.log('markActive() No previous tweet found.');
      }
    }
    if (twtldDebug) {
      console.log('markActive() Mark previous tweet read, id (' + id + ').');
    }
    let url = 'mark-read.php?id=' + id;
    if (twtldUsername) {
      url += '&author=' + encodeURI(twtldUsername);
    }
    else if (twtldListname) {
      url += '&list=' + encodeURI(twtldListname);
    }
    callAjax(url, function (content, status) {
      if (twtldDebug && status !== 200) {
        console.log('markActive() Error marking tweet as read.');
      }
      let results = JSON.parse(content);
      if (results['unread']) {
        let unread = parseInt(results['unread']);
        unread--;
        if (unread < 0) {
          unread = 0;
        }
        if (twtldDebug) {
          console.log('markActive() Setting unread count to (' + unread + ').');
        }
        $('#unread-count').text(unread);
      }
    });
  };

  let getTopItem = function () {

    if (twtldDebug) {
      console.log('getTopItem() Called.');
    }
    let active = $('.active');
    if (active.length > 0 && active.visible(true)) {
      if (twtldDebug) {
        console.log('getTopItem() Active item exists and is visible.');
      }
      return;
    }

    $('.tweetledum-tweet').removeClass('active');
    let activeElement = document.elementFromPoint(300, 100);
    if (twtldDebug) {
      console.log('getTopItem() activeElement is:');
      console.log(activeElement);
    }
    if (!$(activeElement).hasClass('tweetledum-tweet')) {
      if (twtldDebug) {
        console.log('getTopItem() activeElement is not one of our tweets.');
      }
      activeElement = $(activeElement).parents('.tweetledum-tweet').first();
      if (!$(activeElement).hasClass('tweetledum-tweet')) {
        if (twtldDebug) {
          console.log('getTopItem() activeElement does not have a parent that is one of our tweets.');
        }
        if (lastTweetID) {
          if (twtldDebug) {
            console.log('getTopItem() Will use last active tweet.');
          }
          activeElement = $('#tweetledum-' + lastTweetID);
        }
        else {
          activeElement = $('.tweetledum-tweet').first();
        }
      }
    }
    markActive(activeElement);

  };

  $('#load-more').not('.load-processed').click(function () {
    processLoadMoreButton(this);
  }).addClass('load-processed').click();

  $('.tweetledum-controls button').click(function (event) {
    let keyCode = $(this).attr('data-keycode');
    processKeyPress(event, keyCode);
  });

  let processKeyPress = function (event, keyCode) {

    if (!keyCode) {
      keyCode = event.keyCode;
    }

    if (keyCode === 78) {
      // Pressing "n" will bring active tweet to top.
      event.preventDefault();
      if ($('.active').length) {
        if (twtldDebug) {
          console.log('keydown("n"): Scrolling to active tweet (' + $('.active').first().attr('data-id') + ').');
        }
        scrollToElement($('.active')[0]);
      }
      else {
        if (twtldDebug) {
          console.log('keydown("n"): No active tweet found.');
        }
      }
      return;
    }

    getTopItem();
    let activeItem = $('.active');
    if (keyCode === 75) {
      // Pressing "k" will scroll to previous item.
      event.preventDefault();
      let prev = activeItem.prev();
      markActive(prev);
      if (prev.length === 0) {
        return;
      }
      activeItem.removeClass('active');
      scrollToElement($(prev)[0]);
    }
    else if (keyCode === 74) {
      // Pressing "j" will scroll to next item.
      event.preventDefault();
      let next = activeItem.next();
      markActive(next);
      if (next.length === 0) {
        $('#load-more').click();
        return;
      }
      activeItem.removeClass('active');
      scrollToElement($(next)[0]);
    }
    else if (keyCode === 86) {
      // Pressing "v" will open url.
      event.preventDefault();
      let url = activeItem.attr('data-url');
      window.open(url, '_blank');
    }
    else if (keyCode === 84) {
      // Pressing "t" will open tweet.
      event.preventDefault();
      let url = activeItem.attr('data-tweet');
      window.open(url, '_blank');
    }
    else if (keyCode === 82) {
      // Pressing "r" will reload.
      location.reload();
    }
    else if (keyCode === 83) {
      // Pressing "s" will show/hide extra info body.
      activeItem.toggleClass('show-extra');
    }
  };

  let scrollToElement = function (element) {
    $('html, body').animate({
      scrollTop: $(element).offset().top - SCROLL_OFFSET,
    }, '400');
  }

  $(document).keydown(function (event) {
    processKeyPress(event);
  });

  $(window).scroll(function () {
    if (!checkingLoadMore) {
      setTimeout(function () {
        checkingLoadMore = true;
        checkLoadMore();
        setTimeout(function () {
          checkingLoadMore = false;
        }, 100);
      }, 100);
    }
  });

  let checkLoadMore = function () {
    let button = $('#load-more').not('.loading');
    if (button.visible(true) && !noNewTweets) {
      button.addClass('loading');
      $('#unread-count').addClass('loading');
      button.click();
    }
  };

  let isTouchDevice = function () {
    // 1. Works on most browsers.
    // 2. Works on IE10/11 and Surface.
    return 'ontouchstart' in window
    || navigator.maxTouchPoints;
  };

  if (isTouchDevice()) {
    $('.tweetledum-controls').show();
  }

})(jQuery);
