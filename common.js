/* =========================================================================
   АквАренА — common.js
   Строит мобильные карточки (.mobile-cards) из каждой таблицы тарифов
   (.table-scroll.stack), чтобы избежать бага Safari/iOS с перерисовкой
   <tr>/<td> при display:block. Ничего не удаляет и не меняет в остальной
   разметке страницы — только добавляет новый блок сразу после таблицы.
   Стили для .mobile-cards — в common.css.
   ========================================================================= */
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.table-scroll.stack').forEach(function (scroll) {
    // Не строим карточки дважды, если скрипт случайно подключат повторно
    if (scroll.nextElementSibling && scroll.nextElementSibling.classList.contains('mobile-cards')) return;

    var table = scroll.querySelector('table');
    if (!table) return;

    var rows = Array.from(table.querySelectorAll('tr'));
    if (!rows.length) return;
    var headerCells = Array.from(rows[0].querySelectorAll('th,td')).map(function (c) {
      return c.textContent.trim();
    });

    var cardsWrap = document.createElement('div');
    cardsWrap.className = 'mobile-cards';

    rows.slice(1).forEach(function (tr) {
      var mcard = document.createElement('div');
      mcard.className = 'mcard' + (tr.classList.contains('highlight-row') ? ' highlight' : '');

      Array.from(tr.querySelectorAll('td')).forEach(function (td, i) {
        var mrow = document.createElement('div');
        mrow.className = 'mrow';

        var mlabel = document.createElement('span');
        mlabel.className = 'mlabel';
        mlabel.textContent = headerCells[i] || '';

        var mvalue = document.createElement('span');
        mvalue.className = 'mvalue' +
          (td.classList.contains('price') ? ' price' : '') +
          (td.classList.contains('muted') ? ' muted' : '');
        mvalue.textContent = td.textContent.trim();

        mrow.appendChild(mlabel);
        mrow.appendChild(mvalue);
        mcard.appendChild(mrow);
      });

      cardsWrap.appendChild(mcard);
    });

    scroll.insertAdjacentElement('afterend', cardsWrap);
  });
});
