<?php

namespace Drupal\commerce_combine_carts;

use Drupal\commerce_cart\CartProviderInterface;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\user\UserInterface;

class CartUnifier {
  /** @var \Drupal\commerce_cart\CartProviderInterface */
  protected $cartProvider;

  /**
   * CartUnifier constructor.
   *
   * @param \Drupal\commerce_cart\CartProviderInterface $cart_provider
   *   The cart provider.
   */
  public function __construct(CartProviderInterface $cart_provider) {
    $this->cartProvider = $cart_provider;
  }

  /**
   * Returns a user's main cart.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The main cart for the user, or NULL if there is no cart.
   */
  public function getMainCart(UserInterface $user) {
    $carts = $this->cartProvider->getCarts($user);

    return (!empty($carts))
      ? array_shift($carts)
      : NULL;
  }

  /**
   * Assign a cart to a user, possibly moving items to the user's main cart.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $cart
   *   The cart to assign.
   * @param \Drupal\user\UserInterface $user
   *   The user.
   */
  public function assignCart(OrderInterface $cart, UserInterface $user) {
    $this->combineCarts($this->getMainCart($user), $cart, FALSE);
  }

  /**
   * Combines all of a user's carts into their main cart.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user.
   */
  public function combineUserCarts(UserInterface $user) {
    $main_cart = $this->getMainCart($user);
    foreach ($this->cartProvider->getCarts($user) as $cart) {
      $this->combineCarts($main_cart, $cart, TRUE);
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
   */
  public function combineCarts(OrderInterface $main_cart, OrderInterface $other_cart, $delete = FALSE) {
    if ($main_cart->id() !== $other_cart->id()) {
      foreach ($other_cart->getItems() as $item) {
        $other_cart->removeItem($item);
        $item->get('order_id')->entity = $main_cart;
        $main_cart->addItem($item);
      }
      $main_cart->save();

      if ($delete) {
        $other_cart->delete();
      } else {
        $other_cart->save();
      }
    }
  }

}
