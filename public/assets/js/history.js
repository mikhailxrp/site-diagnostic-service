/**
 * История: модальное окно подтверждения удаления. Кнопка строки передаёт в data-* адрес формы,
 * сайт и дату — при открытии окна (show.bs.modal, Bootstrap из tabler.min.js) они подставляются в окно.
 */

const fillDeleteModal = (modal, button) => {
  modal.querySelector('[data-delete-form]').action = button.dataset.deleteUrl ?? '';
  modal.querySelector('[data-delete-site]').textContent = button.dataset.deleteSite ?? '';
  modal.querySelector('[data-delete-date]').textContent = button.dataset.deleteDate ?? '';
};

const modal = document.getElementById('history-delete-modal');

if (modal !== null) {
  modal.addEventListener('show.bs.modal', (event) => {
    if (event.relatedTarget instanceof HTMLElement) {
      fillDeleteModal(modal, event.relatedTarget);
    }
  });
}
