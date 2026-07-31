const FOCUSABLE = 'input, select, textarea, button, [tabindex]';

/**
 * Attach to a form/container's onKeyDown so Enter moves focus to the next
 * field instead of submitting. Textareas and buttons are left alone (newline
 * / native click). Anything that already called preventDefault (e.g. the
 * ComboBox inline "create new" input) is left alone too.
 */
export default function focusNextFieldOnEnter(e) {
    if (e.key !== 'Enter' || e.defaultPrevented) return;

    const target = e.target;
    if (target.tagName === 'TEXTAREA' || target.tagName === 'BUTTON') return;

    const fields = Array.from(e.currentTarget.querySelectorAll(FOCUSABLE))
        .filter((el) => !el.disabled && el.tabIndex !== -1 && el.offsetParent !== null);

    const index = fields.indexOf(target);
    if (index === -1) return;

    e.preventDefault();
    fields[index + 1]?.focus();
}
