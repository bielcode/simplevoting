/**
 * @file
 * Simple Voting Theme — voting.js
 *
 * Aprimora a tabela de resultados com barras de progresso animadas.
 * O texto de percentual na última coluna é substituído por uma barra visual
 * mantendo o valor acessível via aria-label.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.simpleVotingResults = {
    attach(context) {
      once('sv-results', '.sv-results-table', context).forEach((table) => {
        table.querySelectorAll('tbody tr').forEach((row) => {
          const cells = row.querySelectorAll('td');
          if (cells.length < 3) return;

          const pctCell = cells[cells.length - 1];
          const pct = parseFloat(pctCell.textContent);
          if (isNaN(pct)) return;

          pctCell.classList.add('sv-bar-cell');
          pctCell.innerHTML = `
            <div class="sv-bar" role="img" aria-label="${pct}%">
              <div class="sv-bar__track">
                <div class="sv-bar__fill"></div>
              </div>
              <span class="sv-bar__label">${pct}%</span>
            </div>
          `;

          // Dois rAF consecutivos garantem que a transição CSS dispara após a pintura inicial.
          requestAnimationFrame(() => {
            requestAnimationFrame(() => {
              pctCell.querySelector('.sv-bar__fill').style.width = pct + '%';
            });
          });
        });
      });
    },
  };
})(Drupal, once);
