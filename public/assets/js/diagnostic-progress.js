/**
 * Экран диагностики: опрашивает /diagnostics/{id}/status раз в 2 с и обновляет прогресс и шаги.
 * Следующий запрос уходит только после ответа на предыдущий; на done / failed опрос прекращается
 * и страница перезагружается — отчёт (в т. ч. частичный при failed) выводит сервер.
 */

const POLL_INTERVAL_MS = 2000;
const HTTP_NOT_FOUND = 404;
const REPORT_STATUSES = ['done', 'failed'];

const MESSAGE_CONNECTION_LOST = 'Нет связи с сервером — пробую снова…';
const MESSAGE_NOT_FOUND = 'Диагностика не найдена — обновление остановлено.';

const setBadge = (element, badgeClass, label) => {
  element.className = `badge ${badgeClass}`;
  element.textContent = label;
};

const showWarning = (root, text) => {
  const warning = root.querySelector('[data-poll-warning]');
  root.querySelector('[data-poll-warning-text]').textContent = text;
  warning.hidden = false;
};

const hideWarning = (root) => {
  root.querySelector('[data-poll-warning]').hidden = true;
};

const renderSteps = (root, steps) => {
  steps.forEach((step) => {
    const item = root.querySelector(`[data-step="${CSS.escape(step.code)}"]`);
    if (item === null) {
      return;
    }
    setBadge(item.querySelector('[data-step-badge]'), step.badgeClass, step.statusLabel);
    const message = item.querySelector('[data-step-message]');
    message.textContent = step.message ?? '';
    message.hidden = step.message === null;
  });
};

const renderState = (root, statusBadge, state) => {
  const progress = root.querySelector('[data-progress]');
  progress.value = state.progress;
  progress.textContent = `${state.progress}%`;
  root.querySelector('[data-progress-text]').textContent = `${state.progress}%`;

  setBadge(statusBadge, state.badgeClass, state.statusLabel);
  renderSteps(root, state.steps);

  root.querySelector('[data-error-message]').textContent = state.errorMessage ?? '';
  root.querySelector('[data-result-failed]').hidden = state.status !== 'failed';
  root.querySelector('[data-result-report]').hidden = !REPORT_STATUSES.includes(state.status);
};

const startPolling = (root, statusBadge) => {
  const statusUrl = root.dataset.statusUrl;

  const poll = async () => {
    try {
      const response = await fetch(statusUrl, { headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (response.status === HTTP_NOT_FOUND) {
        showWarning(root, MESSAGE_NOT_FOUND);
        return;
      }
      if (!response.ok) {
        throw new Error(`HTTP ${response.status}`);
      }
      const state = await response.json();
      hideWarning(root);
      renderState(root, statusBadge, state);
      if (state.isFinished) {
        if (REPORT_STATUSES.includes(state.status)) {
          window.location.reload();
        }
        return;
      }
    } catch {
      showWarning(root, MESSAGE_CONNECTION_LOST);
    }
    setTimeout(poll, POLL_INTERVAL_MS);
  };

  setTimeout(poll, POLL_INTERVAL_MS);
};

const root = document.querySelector('[data-diagnostic]');
const statusBadge = document.querySelector('[data-diagnostic-status]');

if (root !== null && statusBadge !== null && root.dataset.finished !== '1') {
  startPolling(root, statusBadge);
}
