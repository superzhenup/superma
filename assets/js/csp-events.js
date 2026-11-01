/** Trusted event actions. HTML supplies action names and data, never executable code. */
(function (global, document) {
    'use strict';
    const actions = new Map();
    const actionName = /^[A-Za-z][A-Za-z0-9_.:-]{0,127}$/;

    function invoke(element, event) {
        const name = element.getAttribute('data-csp-' + event.type);
        const handler = name && actions.get(name);
        if (typeof handler === 'function' && handler.call(element, event, element) === false) {
            event.preventDefault();
        }
    }

    function dispatch(event) {
        let element = event.target;
        if (element && element.nodeType !== 1) element = element.parentElement;
        while (element && element !== document) {
            if (typeof element.getAttribute === 'function') invoke(element, event);
            if (event.cancelBubble) break;
            element = element.parentElement;
        }
    }

    // Bubbling preserves nested buttons, dynamic fragments and form cancellation.
    ['click', 'dblclick', 'change', 'input', 'submit', 'keydown', 'keyup', 'keypress',
        'mousedown', 'mouseup', 'mouseover', 'mouseout', 'pointerdown', 'pointerup',
        'touchstart', 'touchend', 'dragstart', 'dragover', 'drop', 'reset', 'focusin', 'focusout']
        .forEach(function (name) { document.addEventListener(name, dispatch); });

    // These events do not bubble; invoke only the actual target's action.
    ['load', 'error', 'focus', 'blur', 'mouseenter', 'mouseleave']
        .forEach(function (name) {
            document.addEventListener(name, function (event) {
                if (event.target && typeof event.target.getAttribute === 'function') invoke(event.target, event);
            }, true);
        });

    global.AppEvents = Object.freeze({
        register: function (name, handler) {
            if (!actionName.test(name) || typeof handler !== 'function') {
                throw new TypeError('A CSP event action requires a fixed name and a function.');
            }
            actions.set(name, handler);
        }
    });
})(window, document);
