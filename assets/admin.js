document.addEventListener('DOMContentLoaded', function () {
    const list = document.querySelector('[data-location-list]');
    const addButton = document.querySelector('[data-add-location]');
    const template = document.querySelector('#hometown-location-template');

    if (!list || !addButton || !template) return;

    let index = list.querySelectorAll('[data-location-row]').length;

    addButton.addEventListener('click', function () {
        const html = template.innerHTML.replaceAll('__INDEX__', index++);
        list.insertAdjacentHTML('beforeend', html);
    });

    list.addEventListener('click', function (event) {
        const remove = event.target.closest('[data-remove-location]');
        if (!remove) return;

        const row = remove.closest('[data-location-row]');
        if (row) row.remove();
    });

    list.addEventListener('input', function (event) {
        if (!event.target.matches('[data-location-name]')) return;
        const row = event.target.closest('[data-location-row]');
        const title = row?.querySelector('[data-location-title]');
        if (title) title.textContent = event.target.value || 'New Location';
    });
});
