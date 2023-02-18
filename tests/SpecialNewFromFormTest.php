<?php
/**
 * Wikibase forms extension
 * Copyright (C) 2018 Adrian Heine <mail@adrianheine.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
declare( strict_types=1 );

namespace MwWikibaseForms\Tests;

use FauxRequest;
use MwWikibaseForms\FormProvider;
use MwWikibaseForms\SpecialNewFromForm;
use PHPUnit\Framework\TestCase;
use RequestContext;
use Status;
use Title;
use Wikibase\DataModel\Entity\BasicEntityIdParser;
use Wikibase\DataModel\Entity\EntityIdValue;
use Wikibase\DataModel\Entity\Item;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Services\Lookup\LabelDescriptionLookup;
use Wikibase\DataModel\Services\Lookup\PropertyDataTypeLookup;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Statement\StatementList;
use Wikibase\EditEntityFactory;
use Wikibase\Lib\Store\EntityStore;
use Wikibase\Lib\Store\EntityTitleLookup;
use Wikibase\Repo\DataTypeValidatorFactory;
use Wikibase\Repo\Validators\ValidatorErrorLocalizer;
use Wikibase\Repo\WikibaseRepo;
use WikibaseForms\FormParser;
use WikibaseForms\Model\Form;

class SpecialNewFromFormTest extends TestCase {

	/**
	 * @dataProvider executeProvider
	 */
	public function testExecute( $editEntity, string $form, $post, $item ) {
		$specialPage = $this->getSpecialPage( $form, $editEntity );

		$context = new RequestContext();
		$context->setTitle( Title::newFromText( "Test" ) );
		$context->setRequest( new FauxRequest( $post, true ) );
		$specialPage->setContext( $context );

		$specialPage->run( "Form" );

		$this->assertEquals( $context->getOutput()->getHtml(), "" );
		$this->assertTrue(
			$item->equals( $editEntity->item ),
			"Item not equal " . print_r( $item, true ) . ", " . print_r( $editEntity->item, true )
		);
	}

	public function executeProvider() {
		$editEntity = new class {

			public function attemptSave( $item ) {
				$this->item = $item;
				return Status::newGood();
			}

		};
		return [
			[ $editEntity, "", [], new Item( new ItemId( "Q333" ) ) ],
			[
				$editEntity,
				"Statement(P1)",
				[ "wp0_0-main" => "Q1" ],
				new Item(
					new ItemId( "Q333" ),
					null,
					null,
					new StatementList( new Statement( new PropertyValueSnak( new PropertyId( "P1" ), new EntityIdValue( new ItemId( "Q1" ) ) ) ) )
				)
			],
			[
				$editEntity,
				"Statement(P1)+",
				[ "wp0_0-main" => "Q1", "wp0_1-main" => "Q5" ],
				new Item( new ItemId( "Q333" ), null, null, new StatementList( [
				new Statement( new PropertyValueSnak( new PropertyId( "P1" ), new EntityIdValue( new ItemId( "Q1" ) ) ) ),
				new Statement( new PropertyValueSnak( new PropertyId( "P1" ), new EntityIdValue( new ItemId( "Q5" ) ) ) )
				] ) )
			],
			[
				$editEntity,
				"Statement(P1)+\n- P1(Q2)",
				[ "wp0_0-main" => "Q1", "wp0_0-0_0" => "", "wp0_1-main" => "Q5", "wp0_1-0_0" => "Q2" ],
				new Item( new ItemId( "Q333" ), null, null, new StatementList( [
				new Statement( new PropertyValueSnak( new PropertyId( "P1" ), new EntityIdValue( new ItemId( "Q1" ) ) ) ),
				new Statement(
					new PropertyValueSnak( new PropertyId( "P1" ), new EntityIdValue( new ItemId( "Q5" ) ) ),
					new SnakList( [
						new PropertyValueSnak( new PropertyId( "P1" ), new EntityIdValue( new ItemId( "Q2" ) ) ),
					] )
				)
				] ) )
			],
			[
				$editEntity,
				"Statements\n- P1+\n- P1(Q2)",
				[ "wp0_0-0_0" => "Q1", "wp0_0-1_0" => "", "wp0_1-0_1" => "Q5", "wp0_1-1_0" => "" ],
				new Item( new ItemId( "Q333" ), null, null, new StatementList( [
				new Statement( new PropertyValueSnak( new PropertyId( "P1" ), new EntityIdValue( new ItemId( "Q1" ) ) ) ),
				new Statement( new PropertyValueSnak( new PropertyId( "P1" ), new EntityIdValue( new ItemId( "Q5" ) ) ) )
				] ) )
			],
			[
				$editEntity,
				"Statements\n- P1+\n- P1(Q2)",
				[ "wp0_0-0_0" => "Q1", "wp0_0-1_0" => "Q2" ],
				new Item( new ItemId( "Q333" ), null, null, new StatementList( [
				new Statement( new PropertyValueSnak( new PropertyId( "P1" ), new EntityIdValue( new ItemId( "Q1" ) ) ) ),
				new Statement( new PropertyValueSnak( new PropertyId( "P1" ), new EntityIdValue( new ItemId( "Q2" ) ) ) )
				] ) )
			],
		];
	}

	private function getSpecialPage( string $form, $editEntity ) {
		$propertyDataTypeLookup = $this->getMock( PropertyDataTypeLookup::class );
		$propertyDataTypeLookup->expects( $this->any() )
		->method( 'getDataTypeIdForProperty' )
		->will( $this->returnCallback( function( $propertyId ) {
			return [
			'P1' => 'wikibase-item'
			][ $propertyId->getSerialization() ];
		} ) );

		$dataTypeValidatorFactory = $this->getMock( DataTypeValidatorFactory::class );
		$dataTypeValidatorFactory->expects( $this->any() )
		->method( 'getValidators' )
		->will( $this->returnValue( [] ) );

		$entityIdParser = new BasicEntityIdParser();
		return new SpecialNewFromForm(
			new class( ( new FormParser( $entityIdParser ) )->parse( $form ) ) implements FormProvider {

				public function __construct( Form $form ) {
					$this->form = $form;
				}

				public function getForm( string $name ): Form {
					return $this->form;
				}

			},
			$this->getMock( LabelDescriptionLookup::class ),
			$this->getEditEntityFactory( $editEntity ),
			$propertyDataTypeLookup,
			WikibaseRepo::getValueParserFactory(),
	/*
			$this->getMockBuilder( ValueParserFactory::class )
				->disableOriginalConstructor()
				->getMock(),
	*/
			$dataTypeValidatorFactory,
			$this->getMock( ValidatorErrorLocalizer::class ),
			$this->getEntityTitleLookup(),
			WikibaseRepo::getCompactBaseDataModelSerializerFactory()->newSnakSerializer( false ),
			WikibaseRepo::getDataValueFactory(),
			$this->getMockBuilder( GuidGenerator::class )
				->disableOriginalConstructor()
				->getMock(),
			WikibaseRepo::getEntityFactory(),
			$this->getMockEntityStore()
		);
	}

	private function getMockEntityStore() {
		$mock = $this->getMock( EntityStore::class );
		$mock->expects( $this->any() )
			->method( 'assignFreshId' )
			->will( $this->returnCallback( function ( Item $entity ) {
				$entity->setId( new ItemId( 'Q333' ) );
			} ) );

		return $mock;
	}

	private function getEntityTitleLookup() {
		$entityTitleLookup = $this->getMock( EntityTitleLookup::class );
		$entityTitleLookup->expects( $this->any() )
		->method( 'getTitleForId' )
		->will( $this->returnCallback( function( ItemId $entityId ) {
			return Title::newFromText( $entityId->getSerialization() );
		} ) );

		return $entityTitleLookup;
	}

	private function getEditEntityFactory( $editEntity ) {
		$editEntityFactory = $this->getMockBuilder( EditEntityFactory::class )
			->disableOriginalConstructor()
			->getMock();
		$editEntityFactory->method( 'newEditEntity' )
			->willReturn( $editEntity );
		return $editEntityFactory;
	}

}
