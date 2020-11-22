<?php

namespace Drupal\Tests\commerce_combine_carts\FunctionalJavascript;

use Drupal\Tests\commerce_cart\FunctionalJavascript\CartWebDriverTestBase;

/**
 * Tests the add to cart form.
 *
 * @group commerce_combine_carts
 */
class LoginCombineCartsTest extends CartWebDriverTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'commerce_combine_carts',
  ];

  /**
   * Tests adding a product to the cart when there are multiple variations.
   */
  public function testCombineCarts() {
    // We are now logged in as $this->adminUser.
    $this->drupalGet($this->variation->getProduct()->toUrl());
    // Add a product to the cart.
    $this->getSession()->getPage()->find('css', '#edit-submit')->click();
    // Log out and do the same.
    $this->drupalLogout();
    $this->drupalGet($this->variation->getProduct()->toUrl());
    $this->getSession()->getPage()->find('css', '#edit-submit')->click();
    // Log in, and assume that we have now gotten those carts combined.
    $this->drupalLogin($this->adminUser);
    $this->drupalGet($this->variation->getProduct()->toUrl());
    // This means we should have an order, with one order item, with 2 in
    // quantity.
    $orders = $this->container->get('commerce_cart.cart_provider')->getCarts($this->adminUser);
    /** @var \Drupal\commerce_order\Entity\OrderInterface $order */
    $order = reset($orders);
    $items = $order->getItems();
    $item = reset($items);
    $this->assertEquals(2, $item->getQuantity());
  }
}
