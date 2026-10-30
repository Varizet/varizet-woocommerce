<?php
declare(strict_types=1);

namespace Varizet\WooCommerce\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Varizet\WooCommerce\Edd\EddStatusMapper as M;

final class EddStatusMapperTest extends TestCase {

	/**
	 * @dataProvider table
	 */
	public function test_decision( string $varizet, string $edd, string $action, ?string $status, ?string $note ): void {
		self::assertSame(
			array(
				'action' => $action,
				'status' => $status,
				'note'   => $note,
			),
			M::decide( $varizet, $edd )
		);
	}

	public static function table(): array {
		return array(
			'paid pending'            => array( 'paid', 'pending', M::COMPLETE, 'complete', 'paid' ),
			'paid on hold'            => array( 'paid', 'on_hold', M::COMPLETE, 'complete', 'paid' ),
			'paid after start failed' => array( 'paid', 'failed', M::COMPLETE, 'complete', 'paid' ),
			'paid after abandoned'    => array( 'paid', 'abandoned', M::COMPLETE, 'complete', 'paid_after_cancel' ),
			'paid after revoked'      => array( 'paid', 'revoked', M::COMPLETE, 'complete', 'paid_after_cancel' ),
			'paid already complete'   => array( 'paid', 'complete', M::NONE, null, null ),
			'paid on refunded'        => array( 'paid', 'refunded', M::NOTE, null, 'paid_after_refund' ),
			'review pending'          => array( 'needs_review', 'pending', M::SET, 'on_hold', 'needs_review' ),
			'underpaid pending'       => array( 'underpaid', 'pending', M::SET, 'on_hold', 'underpaid' ),
			'review on hold already'  => array( 'needs_review', 'on_hold', M::NOTE, null, 'needs_review' ),
			'underpaid abandoned'     => array( 'underpaid', 'abandoned', M::SET, 'on_hold', 'underpaid_after_cancel' ),
			'review on complete'      => array( 'needs_review', 'complete', M::NOTE, null, 'needs_review' ),
			'awaiting after hold'     => array( 'awaiting_payment', 'on_hold', M::SET, 'pending', null ),
			'awaiting pending'        => array( 'awaiting_payment', 'pending', M::NONE, null, null ),
			'expired pending'         => array( 'expired', 'pending', M::SET, 'abandoned', 'expired' ),
			'expired on hold'         => array( 'expired', 'on_hold', M::SET, 'abandoned', 'expired' ),
			'expired complete'        => array( 'expired', 'complete', M::NONE, null, null ),
			'canceled pending'        => array( 'canceled', 'pending', M::SET, 'failed', 'canceled' ),
			'refunded complete'       => array( 'refunded', 'complete', M::SET, 'refunded', 'refunded' ),
			'refunded pending'        => array( 'refunded', 'pending', M::NOTE, null, 'refunded' ),
			'partially refunded'      => array( 'partially_refunded', 'complete', M::NOTE, null, 'partially_refunded' ),
			'unknown'                 => array( 'brand_new', 'pending', M::NONE, null, null ),
		);
	}

	public function test_hold_falls_back_to_processing_without_on_hold(): void {
		self::assertSame( 'processing', M::decide( 'needs_review', 'pending', 'processing' )['status'] );
		self::assertSame( M::NOTE, M::decide( 'needs_review', 'processing', 'processing' )['action'] );
	}

	public function test_idempotency_key_and_currency(): void {
		self::assertSame( 'edd-42-2-3f9a1c0d', M::idempotency_key( 42, 2, '3F9A1C0D-extra' ) );
		self::assertSame( 'edd-42-1', M::idempotency_key( 42, 1, '' ) );
		self::assertSame( 'IRT', M::currency( 'toman' ) );
		self::assertSame( 'IRR', M::currency( ' rial ' ) );
		self::assertSame( 'IRT', M::currency( 'IRT' ) );
		$this->expectException( \InvalidArgumentException::class );
		M::idempotency_key( 0, 1, 'x' );
	}
}
