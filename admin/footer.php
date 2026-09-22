        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<style>
    /* Small pop-up used by the SMS / WhatsApp buttons (kept clear of Bootstrap's own dialogs). */
    .hef-composer { position: fixed; top: 0; right: 0; bottom: 0; left: 0; z-index: 3000; display: flex; align-items: center; justify-content: center; padding: 1rem; }
    .hef-composer-backdrop { position: absolute; top: 0; right: 0; bottom: 0; left: 0; background: rgba(0, 0, 0, .45); }
    .hef-composer-card { position: relative; width: 100%; max-width: 460px; }
</style>
<script>
// Opens a box to review or type a message, then sends it through the company's
// SMS or WhatsApp Business account (admin/contact-send.php). $btn carries
// data-channel ("sms" or "whatsapp"), data-phone (international digits) and
// data-message (the pre-filled text).
function hefOpenComposer(btn) {
    var channel = btn.dataset.channel;
    var phone = btn.dataset.phone;
    var label = channel === 'sms' ? 'SMS' : 'WhatsApp';
    // Inside a Bootstrap dialog, the box must live inside it or the dialog's focus trap would steal typing.
    var host = btn.closest('.modal') || document.body;

    var box = document.createElement('div');
    box.className = 'hef-composer';
    box.innerHTML =
        '<div class="hef-composer-backdrop"></div>' +
        '<div class="hef-composer-card card shadow-lg" role="dialog" aria-modal="true">' +
            '<div class="card-header bg-white d-flex justify-content-between align-items-center"><strong class="hef-c-title"></strong><button type="button" class="btn-close hef-c-close" aria-label="Close"></button></div>' +
            '<div class="card-body"><textarea class="form-control" rows="6" maxlength="1000" placeholder="Type your message"></textarea><div class="small mt-2 hef-c-status"></div></div>' +
            '<div class="card-footer bg-white d-flex gap-2"><button type="button" class="btn btn-outline-secondary flex-fill hef-c-close">Cancel</button><button type="button" class="btn btn-success flex-fill hef-c-send">Send</button></div>' +
        '</div>';
    box.querySelector('.hef-c-title').textContent = label + ' to +' + phone;
    var textarea = box.querySelector('textarea');
    var status = box.querySelector('.hef-c-status');
    var sendBtn = box.querySelector('.hef-c-send');
    textarea.value = btn.dataset.message || '';

    function close() { box.remove(); }
    box.addEventListener('click', function (e) {
        if (e.target.closest('.hef-c-close') || e.target.classList.contains('hef-composer-backdrop')) { close(); }
    });
    box.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.stopPropagation(); close(); }
    });

    sendBtn.addEventListener('click', function () {
        var text = textarea.value.trim();
        if (!text) { status.className = 'small mt-2 text-danger'; status.textContent = 'Type a message first.'; return; }
        sendBtn.disabled = true;
        status.className = 'small mt-2 text-muted';
        status.textContent = 'Sending…';
        fetch('/hef/admin/contact-send.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ channel: channel, phone: phone, message: text })
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res.ok) {
                status.className = 'small mt-2 text-success';
                status.textContent = res.message || 'Sent.';
                setTimeout(close, 1400);
                return;
            }
            sendBtn.disabled = false;
            status.className = 'small mt-2 text-danger';
            status.textContent = res.error || 'The message could not be sent.';
            if (res.fallback_url) {
                var a = document.createElement('a');
                a.href = res.fallback_url;
                a.target = '_blank';
                a.rel = 'noopener';
                a.className = 'btn btn-sm btn-outline-success d-block mt-2';
                a.textContent = 'Open in WhatsApp instead';
                status.appendChild(a);
            }
        }).catch(function () {
            sendBtn.disabled = false;
            status.className = 'small mt-2 text-danger';
            status.textContent = 'Could not reach the server. Please try again.';
        });
    });

    host.appendChild(box);
    textarea.focus();
}
</script>
</body>
</html>
