<?php

declare(strict_types=1);

namespace StripeIntegration\Tax\Test\Mftf\Helper;

use Facebook\WebDriver\WebDriverBy;
use Magento\FunctionalTestingFramework\Helper\Helper;

class PlaceOrderHelper extends \Magento\FunctionalTestingFramework\Helper\Helper
{
    // Clicks the place order button, but does not wait for the DOM ready event because a redirect is expected.
    // Speeds up tests for redirect based payment methods
    public function placeOrderRedirect($buttonSelector)
    {
        $magentoWebDriver = $this->getModule('\Magento\FunctionalTestingFramework\Module\MagentoWebDriver');
        $webDriver = $magentoWebDriver->webDriver;

        $placeOrderButton = $webDriver->findElements(WebDriverBy::cssSelector($buttonSelector));
        if (!empty($placeOrderButton))
            $placeOrderButton[0]->click(); // $magentoWebDriver->click($buttonSelector);
        else
            $this->fail("Place Order button not found: $buttonSelector");
    }
}
