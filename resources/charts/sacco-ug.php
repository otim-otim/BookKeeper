<?php

use OtimOtim\BookKeeper\Charts\EastAfricanSaccoChart;

/*
|--------------------------------------------------------------------------
| Uganda SACCO (UMRA) chart of accounts
|--------------------------------------------------------------------------
|
| Built from the shared East African SACCO structure; see
| OtimOtim\BookKeeper\Charts\EastAfricanSaccoChart. Names are in English
| and Swahili (bookkeeper:install --locale=sw).
|
*/

return EastAfricanSaccoChart::build('UG');
