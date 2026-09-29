import './bootstrap';

import Chart from 'chart.js/auto';
import collapse from '@alpinejs/collapse';
import Alpine from 'alpinejs';

window.Chart = Chart;
window.Alpine = Alpine;

Alpine.plugin(collapse);
Alpine.start();
