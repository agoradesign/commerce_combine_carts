<?php

namespace Drupal\commerce_combine_carts;

use Drupal\commerce_cart\CartManagerInterface;
use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItemInterface;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\user\UserInterface;

class CartUnifier {

  /** @var \Drupal\commerce_cart\CartProviderInterface */
  protected $cartProvider;

  /** @var \Drupal\commerce_cart\CartManagerInterface */
  protected $cartManager;

  /** @var \Drupal\Core\Routing\RouteMatchInterface $routeMatch */
  protected $routeMatch;

  /**
   * CartUnifier constructor.
   *
   * @param \Drupal\commerce_cart\CartProviderInterface $cart_provider
   *   The cart provider.
   * @param \Drupal\commerce_cart\CartManagerInterface $cart_manager
   *   The cart manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match.
   */
  public function __construct(CartProviderInterface $cart_provider, CartManagerInterface $cart_manager, RouteMatchInterface $route_match) {
    $this->cartProvider = $cart_provider;
    $this->cartManager = $cart_manager;
    $this->routeMatch = $route_match;
  }

  /**
   * Returns main carts, one per order type.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface[]|NULL
   *   The main carts for the user, or NULL if there is no cart.
   */
  public function getMainCarts(UserInterface $user) {
    // Clear cart cache so that newly assigned carts are available.
    $this->cartProvider->clearCaches();
    $carts = $this->cartProvider->getCarts($user);
    if (empty($carts)) {
      return NULL;
    }
    // Loop over carts. If there are several of the same type, we overwrite it
    // with the latest one.
    $carts_per_type = [];
    foreach ($carts as $cart) {
      $carts_per_type[$cart->bundle()] = $cart;
    }
    return $carts_per_type;
  }

  /**
   * Assign a cart to a user, possibly moving items to the user's main cart.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart to assign.
   * @param \Drupal\user\UserInterface $user
   *   The user.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function assignCart(OrderInterface $cart, UserInterface $user) {
    $main_carts = $this->getMainCarts($user);

    if ($main_carts) {
      foreach ($main_carts as $main_cart) {
        if ($cart->bundle() != $main_cart->bundle()) {
          continue;
        }
        if ($this->isCartRequestedForCheckout($cart)) {
          $this->combineCarts($cart, $main_cart, FALSE);
        }
        else {
          $this->combineCarts($main_cart, $cart, FALSE);
        }
      }
    }
  }

  /**
   * Combines all of a user's carts into their main cart.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function combineUserCarts(UserInterface $user) {
    $main_carts = $this->getMainCarts($user);

    if ($main_carts) {
      foreach ($main_carts as $main_cart) {
        foreach ($this->cartProvider->getCarts($user) as $cart) {
          if ($cart->bundle() != $main_cart->bundle()) {
            continue;
          }
          $this->combineCarts($main_cart, $cart, TRUE);
        }
      }
    }
  }

  /**
   * Combines another cart into the main cart and optionally deletes the other
   * cart.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $main_cart
   *   The main cart.
   * @param \Drupal\commerce_order\Entity\OrderInterface $other_cart
   *   The other cart.
   * @param bool $delete
   *   TRUE to delete the other cart when finished, FALSE to save it as empty.
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function combineCarts(OrderInterface $main_cart, OrderInterface $other_cart, $delete = FALSE) {
    if ($main_cart->id() === $other_cart->id()) {
      return;
    }
    if ($this->isCartRequestedForCheckout($other_cart)) {
      return $this->combineCarts($other_cart, $main_cart, $delete);
    }

    foreach ($other_cart->getItems() as $item) {
      $other_cart->removeItem($item);
      $item->get('order_id')->entity = $main_cart;
      $combine = $this->shouldCombineItem($item);
      $this->cartManager->addOrderItem($main_cart, $item, $combine);
    }

    $main_cart->save();

    if ($delete) {
      $other_cart->delete();
    } else {
      $other_cart->save();
    }
  }

  /**
   * Determine if a line item should be combined with like items.
   *
   * @param OrderItemInterface $item
   *   The order item.
   *
   * @return bool
   *   TRUE if items should be combined, FALSE otherwise.
   */
  private function shouldCombineItem(OrderItemInterface $item) {
    /** @var \Drupal\commerce_product\Entity\ProductVariationInterface $purchased_entity */
    $purchased_entity = $item->getPurchasedEntity();

    // Do not combine products which are no longer available in system.
    if (!($purchased_entity instanceof ProductVariationInterface)) {
      return FALSE;
    }

    $product = $purchased_entity->getProduct();
    $entity_display = EntityViewDisplay::load($product->getEntityTypeId() . '.' . $product->bundle() . '.default');
    $combine = TRUE;

    if ($component = $entity_display->getComponent('variations')) {
      $combine = !empty($component['settings']['combine']);
    }

    return $combine;
  }

  /**
   * Returns the cart requested for checkout.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   */
  protected function getCartRequestedForCheckout() {
    if ($this->routeMatch->getRouteName() === 'commerce_checkout.form') {
      $requested_order = $this->routeMatch->getParameter('commerce_order');

      if ($requested_order) {
        return $requested_order;
      }
    }

    return NULL;
  }

  /**
   * Returns TRUE if given cart is requested for checkout.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *
   * @return bool
   */
  protected function isCartRequestedForCheckout(OrderInterface $cart) {
    $requested_cart = $this->getCartRequestedForCheckout();
    return $requested_cart && $requested_cart->id() === $cart->id();
  }

}
