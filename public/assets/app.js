/* =====================================================================
   Campus Food System — the entire client-side script (no frameworks, no CDN)

   There is deliberately no JavaScript in the HTML. The pages carry a strict
   Content-Security-Policy of script-src 'self' (see includes/security.php),
   which means the browser refuses to run any inline <script> or on*=""
   handler. Everything interactive therefore lives here, found by data
   attributes:

     data-confirm="Delete this dish?"   ask before submitting that form
     data-autosubmit                    submit the form when this field changes

   It is all progressive enhancement: with scripting off, the cart's − and +
   buttons are ordinary submit buttons and the admin forms still work — the
   only losses are the "are you sure?" prompt and the auto-submit on typing.
   ===================================================================== */

(function () {
    'use strict';

    /* ---------------------------------------------------------- confirm
       A form carrying data-confirm asks before it goes through. Used for
       destructive things: delete, empty the cart, purge the demo data. */
    document.addEventListener('submit', function (event) {
        var form = event.target;
        var message = form.getAttribute && form.getAttribute('data-confirm');

        if (message && !window.confirm(message)) {
            event.preventDefault();
            return false;
        }
    }, true);

    /* ------------------------------------------------------ autosubmit
       The cart's quantity field submits itself on change, so a student can
       type a number and move on without hunting for a "Set" button. */
    document.addEventListener('change', function (event) {
        var field = event.target;

        if (!field.hasAttribute || !field.hasAttribute('data-autosubmit')) {
            return;
        }

        var form = field.form || field.closest('form');
        if (!form) {
            return;
        }

        // requestSubmit() runs validation and fires the submit event, which
        // matters because the confirm handler above listens for it.
        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    });

    /* ------------------------------------------- keep a dirty form safe
       The item and category editors are long on a phone. Losing twenty
       fields to a stray back-swipe is miserable, so ask first. Only armed
       once something has actually been typed, and disarmed on submit. */
    var armed = false;

    function armDirtyWatch(form) {
        if (!form.hasAttribute('data-dirty-check')) {
            return;
        }

        form.addEventListener('input', function () { armed = true; });
        form.addEventListener('submit', function () { armed = false; });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var forms = document.querySelectorAll('form[data-dirty-check]');
        for (var i = 0; i < forms.length; i++) {
            armDirtyWatch(forms[i]);
        }
    });

    window.addEventListener('beforeunload', function (event) {
        if (!armed) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';        // some browsers still need the string
        return '';
    });

    /* --------------------------------------------------- sticky nav mark
       The tab bar and the admin sub-nav scroll with the page on very small
       screens; keeping the active item in view is a nicety, not a need. */
    document.addEventListener('DOMContentLoaded', function () {
        var active = document.querySelector('.tabbar a.active, .admin-nav a.active');
        if (active && active.scrollIntoView) {
            active.scrollIntoView({ block: 'nearest', inline: 'center' });
        }
    });
}());
