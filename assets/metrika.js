/* Яндекс.Метрика, счётчик 111532968.
 *
 * Отдельным файлом, а не четырьмя копиями врезкой в страницы: номер
 * и настройки должны жить в одном месте. Забыть поправить одну страницу
 * из четырёх — самый обычный способ получить счётчик, который считает
 * не то. Подключается на всех страницах, включая политику и поддержку:
 * без них картина «сколько людей приходило» неполная.
 *
 * webvisor выключен намеренно. Он пишет движения мыши, прокрутку и ввод
 * в поля, а на этом сайте форма отзывов со свободным текстом и адресом
 * почты — то есть запись сеанса собирала бы ровно то, чего мы обещаем
 * не собирать. Вопрос «сколько людей пришло» решается без него.
 * Включать — здесь, одним словом, и сразу править privacy.html:
 * там сказано, что записи сеансов нет.
 *
 * ecommerce из стандартной врезки убран: торговли на сайте нет,
 * и пустой dataLayer заводить незачем.
 */
(function (m, e, t, r, i, k, a) {
  m[i] = m[i] || function () { (m[i].a = m[i].a || []).push(arguments); };
  m[i].l = 1 * new Date();
  for (var j = 0; j < document.scripts.length; j++) {
    if (document.scripts[j].src === r) { return; }
  }
  k = e.createElement(t); a = e.getElementsByTagName(t)[0];
  k.async = 1; k.src = r; a.parentNode.insertBefore(k, a);
})(window, document, 'script', 'https://mc.yandex.ru/metrika/tag.js?id=111532968', 'ym');

ym(111532968, 'init', {
  ssr: true,
  webvisor: false,
  clickmap: true,
  trackLinks: true,
  accurateTrackBounce: true,
  referrer: document.referrer,
  url: location.href
});
