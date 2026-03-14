(function ($) {

  let $checkboxes = $('#bulk-mark-read .checkbox input');

  $('#bulk-toggle').change(function(e) {
    $items = $('.bulk-mark-read .item');
    $('label[for="bulk-toggle"] span').toggle();
    if (this.checked) {
      $checkboxes.prop('checked', true);
      $items.addClass('selected');
    } else {
      $checkboxes.prop('checked', false);
      $items.removeClass('selected');
    }
    count_selected();
  });

  $checkboxes.change(function() {
    let $item = $(this).parents('div.item');
    if (this.checked) {
      $item.addClass('selected');
    }
    else {
      $item.removeClass('selected');
    }
    count_selected();
  });

  let count_selected = function() {
    let count = 0;
    let num = 0;
    let $checkboxes = $('#bulk-mark-read .checkbox input:checked');
    $checkboxes.each(function(e) {
      $(this).parents('div.item').addClass('selected');
      num = parseInt($(this).attr('data-count'));
      if (num) {
        count += num;
      }
    });
    $('#total-selected').html(count);
    let unread = $('#unread-count').html();
    $('#total-remaining').html(unread - count);
  };

  count_selected();

})(jQuery);
