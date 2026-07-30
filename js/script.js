(function (OC, $) {
    'use strict';

    $(document).ready(function () {
        var timer = setInterval(function () {
            var $importBtn = $('#import_google_contacts');
            if ($importBtn.length > 0 && $('#gcontact_sync_enabled').length === 0) {
                var html = `
                    <span style="margin-left: 20px; display: inline-block; vertical-align: middle;">
                        <input type="checkbox" id="gcontact_sync_enabled" class="checkbox">
                        <label for="gcontact_sync_enabled">后台自动全字段同步</label>
                        <span id="gcontact_msg" style="display:none; color:#2d7d32; margin-left:8px;">✓ 已生效</span>
                    </span>
                `;
                $importBtn.after(html);

                // 读取当前设置状态
                $.get(OC.generateUrl('/apps/google_contact_sync/status'), function (res) {
                    if (res && res.enabled) {
                        $('#gcontact_sync_enabled').prop('checked', true);
                    }
                });

                // 更改即发 POST 存储
                $('#gcontact_sync_enabled').on('change', function () {
                    var enabled = $(this).is(':checked');
                    $.post(OC.generateUrl('/apps/google_contact_sync/status'), { enabled: enabled }, function () {
                        $('#gcontact_msg').fadeIn().delay(1800).fadeOut();
                    });
                });
            }
        }, 1000);
    });
})(OC, jQuery);
