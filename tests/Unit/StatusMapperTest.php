<?php
declare(strict_types=1);

namespace Varizet\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Varizet\WooCommerce\Order\StatusMapper as M;

final class StatusMapperTest extends TestCase {

	/**
	 * docs/10 §1.4 mapping table.
	 *
	 * @dataProvider table
	 */
	public function test_mapping( string $vz, string $wc, array $opts, string $action, ?string $status ): void {
		$d = M::decide( $vz, $wc, $opts );
		self::assertSame( $action, $d['action'], "{$vz} on {$wc}" );
		self::assertSame( $status, $d['status'], "{$vz} on {$wc}" );
	}

	public static function table(): array {
		$restock = array( 'restock_on_expire' => true );
		$keep    = array( 'restock_on_expire' => false );
		return array(
			'awaiting stays pending'            => array( M::AWAITING, 'pending', array(), M::NONE, null ),
			'awaiting after review hold'        => array( M::AWAITING, 'on-hold', array(), M::SET_PENDING, 'pending' ),
			'awaiting never touches processing' => array( M::AWAITING, 'processing', array(), M::NONE, null ),
			'review -> on-hold'                 => array( M::NEEDS_REVIEW, 'pending', array(), M::SET_ON_HOLD, 'on-hold' ),
			'underpaid -> on-hold'              => array( M::UNDERPAID, 'pending', array(), M::SET_ON_HOLD, 'on-hold' ),
			'underpaid with wc prefix'          => array( M::UNDERPAID, 'wc-pending', array(), M::SET_ON_HOLD, 'on-hold' ),
			'review after cancel reopens'       => array( M::NEEDS_REVIEW, 'cancelled', array(), M::REOPEN_ON_HOLD, 'on-hold' ),
			'review on processing: note'        => array( M::NEEDS_REVIEW, 'processing', array(), M::NOTE, null ),
			'paid from pending'                 => array( M::PAID, 'pending', array(), M::PAYMENT_COMPLETE, null ),
			'paid from on-hold'                 => array( M::PAID, 'on-hold', array(), M::PAYMENT_COMPLETE, null ),
			'paid from failed'                  => array( M::PAID, 'failed', array(), M::PAYMENT_COMPLETE, null ),
			'paid after wc cancel reopens'      => array( M::PAID, 'cancelled', array(), M::REOPEN_AND_COMPLETE, null ),
			'paid already processing'           => array( M::PAID, 'processing', array(), M::NONE, null ),
			'paid already completed'            => array( M::PAID, 'completed', array(), M::NONE, null ),
			'paid on refunded: note'            => array( M::PAID, 'refunded', array(), M::NOTE, null ),
			'expired + restock'                 => array( M::EXPIRED, 'pending', $restock, M::CANCEL, 'cancelled' ),
			'expired keep stock'                => array( M::EXPIRED, 'pending', $keep, M::FAIL, 'failed' ),
			'expired on on-hold'                => array( M::EXPIRED, 'on-hold', $restock, M::CANCEL, 'cancelled' ),
			'expired never cancels paid'        => array( M::EXPIRED, 'processing', $restock, M::NONE, null ),
			'expired on cancelled'              => array( M::EXPIRED, 'cancelled', $restock, M::NONE, null ),
			'canceled -> cancelled'             => array( M::CANCELED, 'pending', array(), M::CANCEL, 'cancelled' ),
			'canceled on completed'             => array( M::CANCELED, 'completed', array(), M::NONE, null ),
			'refunded -> refunded'              => array( M::REFUNDED, 'processing', array(), M::REFUND, 'refunded' ),
			'refunded twice'                    => array( M::REFUNDED, 'refunded', array(), M::NONE, null ),
			'partial refund note'               => array( M::PARTIALLY_REFUNDED, 'completed', array(), M::NOTE, null ),
			'unknown status'                    => array( 'something_new', 'pending', array(), M::NONE, null ),
		);
	}

	public function test_restock_defaults_to_true(): void {
		self::assertSame( M::CANCEL, M::decide( M::EXPIRED, 'pending' )['action'] );
	}

	public function test_regressions_from_settled_states(): void {
		self::assertTrue( M::is_regression( M::PAID, M::AWAITING ) );
		self::assertTrue( M::is_regression( M::PAID, M::NEEDS_REVIEW ) );
		self::assertTrue( M::is_regression( M::PAID, M::EXPIRED ) );
		self::assertTrue( M::is_regression( M::REFUNDED, M::PAID ) );
		self::assertTrue( M::is_regression( M::PARTIALLY_REFUNDED, M::PAID ) );
		self::assertFalse( M::is_regression( M::PAID, M::REFUNDED ) );
		self::assertFalse( M::is_regression( M::PAID, M::PAID ) );
		self::assertFalse( M::is_regression( M::EXPIRED, M::PAID ), 'late payment after expiry must apply' );
		self::assertFalse( M::is_regression( M::NEEDS_REVIEW, M::AWAITING ) );
		self::assertFalse( M::is_regression( null, M::AWAITING ) );
		self::assertFalse( M::is_regression( '', M::PAID ) );
	}

	public function test_open_and_terminal_sets(): void {
		self::assertTrue( M::is_open( M::AWAITING ) );
		self::assertTrue( M::is_open( M::UNDERPAID ) );
		self::assertFalse( M::is_open( M::PAID ) );
		self::assertTrue( M::is_terminal( M::EXPIRED ) );
		self::assertFalse( M::is_terminal( M::NEEDS_REVIEW ) );
	}
}
