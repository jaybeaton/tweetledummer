(function ($) {

  let $checkboxes = $('#bulk-mark-read .checkbox input');

  $('#bulk-toggle').change(function(e) {
    if (this.checked) {
      $checkboxes.prop('checked', true);
    } else {
      $checkboxes.prop('checked', false);
    }
    count_selected();
  });

  $checkboxes.change(function() {
    count_selected();
  });

  let count_selected = function() {
    let count = 0;
    let $checkboxes = $('#bulk-mark-read .checkbox input:checked');
    $checkboxes.each(function(e) {
      count += parseInt($(this).attr('data-count'));
    });
    $('#total-selected').html(count);
    let unread = $('#unread-count').html();
    $('#total-remaining').html(unread - count);
  };

  count_selected();

})(jQuery);
