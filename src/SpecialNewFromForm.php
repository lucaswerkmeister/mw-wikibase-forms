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

namespace MwWikibaseForms;

use HTMLHiddenField;
use OOUIHTMLForm;
use RequestContext;
use SpecialPage;
use Status;
use ValueParsers\ParseException;
use ValueParsers\ParserOptions;
use ValueParsers\ValueParser;
use Wikibase\DataModel\Entity\EntityId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\Statement;
use Wikibase\DataModel\Statement\StatementList;
use Wikibase\DataModel\Services\Lookup\LabelDescriptionLookup;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\Repo\Validators\CompositeValidator;
use Wikibase\Repo\WikibaseRepo;
use WikibaseForms\Model\Form;
use WikibaseForms\Model\Snak as FormSnak;
use WikibaseForms\Model\StatementSection;

class SpecialNewFromForm extends SpecialPage {
	private $labelLookup;
	private $editEntityFactory;
	private $propertyDataTypeLookup;
	private $valueParserFactory;

	public static function fromGlobalScope() : SpecialNewFromForm {
		$context = RequestContext::getMain();
		return new self(
			new MediaWikiFormProvider( WikibaseRepo::getEntityIdParser() ),
			WikibaseRepo::getLanguageFallbackLabelDescriptionLookupFactory()->newLabelDescriptionLookup( $context->getLanguage() ),
			WikibaseRepo::getEditEntityFactory(),
			WikibaseRepo::getPropertyDataTypeLookup(),
			WikibaseRepo::getValueParserFactory(),
			WikibaseRepo::getDataTypeValidatorFactory(),
			WikibaseRepo::getValidatorErrorLocalizer(),
			WikibaseRepo::getEntityTitleLookup(),
			WikibaseRepo::getCompactBaseDataModelSerializerFactory()->newSnakSerializer( false ),
			WikibaseRepo::getDataValueFactory(),
			new GuidGenerator(),
			WikibaseRepo::getEntityFactory(),
			WikibaseRepo::getEntityStore()
		);
	}

	public function __construct(
		FormProvider $formProvider,
		LabelDescriptionLookup$labelLookup,
		$editEntityFactory,
		$propertyDataTypeLookup,
		$valueParserFactory,
		$dataTypeValidatorFactory,
		$validatorErrorLocalizer,
		$entityTitleLookup,
		$snakSerializer,
		$dataValueFactory,
		$guidGenerator,
		$entityFactory,
		$entityStore
	) {
		$this->formProvider = $formProvider;
		$this->labelLookup = $labelLookup;
		$this->editEntityFactory = $editEntityFactory;
		$this->propertyDataTypeLookup = $propertyDataTypeLookup;
		$this->valueParserFactory = $valueParserFactory;
		$this->dataTypeValidatorFactory = $dataTypeValidatorFactory;
		$this->validatorErrorLocalizer = $validatorErrorLocalizer;
		$this->entityTitleLookup = $entityTitleLookup;
		$this->snakSerializer = $snakSerializer;
		$this->dataValueFactory = $dataValueFactory;
		$this->guidGenerator = $guidGenerator;
		$this->entityFactory = $entityFactory;
		$this->entityStore = $entityStore;

		parent::__construct( 'NewFromForm', "createpage" );
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();
		$this->outputHeader();
		$output = $this->getOutput();
		if ( !$subPage ) {
			$output->addHTML( "No subPage specified." ); // FIXME: Message
			return;
		}

		try {
			$form = $this->formProvider->getForm( $subPage );
		} catch ( Exception $e ) {
			$output->addHTML( "not found" ); // FIXME: Message
			return;
		}

		$output->addModuleStyles( "ext.wb-forms.style" );
		$output->addModules( 'ext.wb-forms.init' );

		$sections = [];
		foreach ( $form->getSections() as $idx => $section ) {
			$sections[$idx] = [];

			if ( $section instanceof StatementSection ) {
				$sections[$idx]["main"] = $this->getFieldForSnak( $section->getMain() );
				foreach ( $section->getQualifiers() as $snakIdx => $snak ) {
					$sections[$idx]["$snakIdx"] = $this->getFieldForSnak( $snak->getSnak() );
				}
			} else {
				foreach ( $section->getStatements() as $snakIdx => $snak ) {
					if ( $snak->getQuantifier() === '+' ) {
						$sections[$idx]["plus-$snakIdx"] = [
							"class" => "HTMLSubmitField",
							"buttonlabel" => "+",
							"cssclass" => "mw-wb-forms-add-statement"
						];
					}
					$sections[$idx]["$snakIdx"] = $this->getFieldForSnak( $snak->getSnak() );
				}
			}
		}

		$hasPlus = false;
		$sections_instances = [];
		$fields_instances = [];
		$values = $this->getRequest()->getValues();
		foreach ( $values as $key => $v ) {
			if ( preg_match( "/^wp((plus-(\d+)_\d+$)|((\d+)_(\d+)-([^_]+)(?:_(\d+))?)|(plus-(\d+_\d+)-([^_]+)_(\d+)$))/", $key, $matches ) ) {
				if ( isset( $matches[5] ) && $matches[5] !== "" ) {
					if ( !isset( $sections_instances[$matches[5]] ) || end( $sections_instances[$matches[5]] ) !== $matches[6] ) {
						$sections_instances[$matches[5]][] = $matches[6];
					}
					$section = $matches[5] . "_" . $matches[6];
					if ( isset( $matches[8] ) && ( !isset( $fields_instances[$section][$matches[7]] ) || end( $fields_instances[$section][$matches[7]] ) !== $matches[8] ) ) {
						$fields_instances[$section][$matches[7]][] = $matches[8];
					}
				} elseif ( isset( $matches[3] ) && $matches[3] !== "" ) {
					$sections_instances[$matches[3]][] = "plus";
					$hasPlus = true;
				} else {
					$fields_instances[$matches[10]][$matches[11]][] = "plus";
					$hasPlus = true;
				}
			}
		}

		$fields = [];
		foreach ( $sections as $idx => $section ) {
			$instances = isset( $sections_instances[$idx] ) ? $sections_instances[$idx] : [ 0 ];
			$instances = $this->handlePlus( $instances );
			$section_definition = $form->getSections()[$idx];
			foreach ( $instances as $i ) {
				$instance_key = "{$idx}_$i";
				foreach ( $section as $field_id => $field ) {
					if ( $field_id === 'plus' ) {
						$fields["plus-$instance_key"] = [ "section" => $instance_key ] + $field;
					} elseif ( is_int( $field_id ) ) {
						$field_instances = isset( $fields_instances[$instance_key][$field_id] ) ? $fields_instances[$instance_key][$field_id] : [ 0 ];
						$field_instances = $this->handlePlus( $field_instances );
						foreach ( $field_instances as $field_i ) {
							if ( isset( $section["plus-$field_id"] ) ) {
								$fields["plus-$instance_key-{$field_id}_$field_i"] = [ "section" => $instance_key ] + $section["plus-$field_id"];
							}
							$field_key = "$instance_key-{$field_id}_$field_i";
							$snak_definition = ( $section_definition instanceof StatementSection ? $section_definition->getQualifiers() : $section_definition->getStatements() )[$field_id]->getSnak();
							$fields[$field_key] = [
								"section" => $instance_key,
								"validation-callback" => function( $value ) use ( $field_key, $snak_definition ) {
									return $this->validateField( $value, $snak_definition, $field_key );
								}
							] + $field;
							$fields["$field_key-hidden"] = [
								"class" => ParsedHiddenField::class,
								"output-as-default" => false,
								"parse" => function( $value ) use( $field_key, $snak_definition ) {
									return $this->getSerialized( $value, $snak_definition, $field_key );
								}
							];
						}
					} elseif ( substr( $field_id, 0, 4 ) !== "plus" ) {
						$field_key = "$instance_key-$field_id";
						$snak_definition = $section_definition->getMain();
						$fields[$field_key] = [
							"section" => $instance_key,
							"validation-callback" => function( $value ) use ( $field_key, $snak_definition ) {
								return $this->validateField( $value, $snak_definition, $field_key );
							}
						] + $field;
						$fields["$field_key-hidden"] = [
							"class" => ParsedHiddenField::class,
							"output-as-default" => false,
							"parse" => function( $value ) use( $field_key, $snak_definition ) {
								return $this->getSerialized( $value, $snak_definition, $field_key );
							}
						];
					}
				}
			}
		}

		$htmlForm = new MyHTMLForm( $fields, $this->getContext() );
		foreach ( $form->getSections() as $idx => $section ) {
			$label = $section->getLabel();
			$instances = isset( $sections_instances[$idx] ) ? $sections_instances[$idx] : [ 0 ];
			$instances = $this->handlePlus( $instances );
			foreach ( $instances as $i ) {
				$instance_key = "{$idx}_$i";
				$htmlForm->setLegend( $label /*. ($i > 0 ? " " . ($i + 1) : "")*/, $instance_key );
				if ( $section instanceof StatementSection && $section->getQuantifier() === '+' ) {
					$htmlForm->setHeaderText( '<div class="mw-htmlform-field-HTMLSubmitField mw-wb-forms-add-section"><span class="mw-htmlform-submit mw-wb-forms-add-section oo-ui-widget oo-ui-widget-enabled oo-ui-flaggedElement-primary oo-ui-flaggedElement-progressive oo-ui-inputWidget oo-ui-buttonElement oo-ui-buttonElement-framed oo-ui-labelElement oo-ui-buttonInputWidget" data-ooui="{&quot;_&quot;:&quot;OO.ui.ButtonInputWidget&quot;,&quot;type&quot;:&quot;submit&quot;,&quot;name&quot;:&quot;wpplus-' . $instance_key . '&quot;,&quot;flags&quot;:[&quot;primary&quot;,&quot;progressive&quot;],&quot;label&quot;:{&quot;html&quot;:&quot;+&quot;},&quot;classes&quot;:[&quot;mw-htmlform-submit&quot;,&quot;mw-wb-forms-add-section&quot;]}"><button type="submit" tabindex="0" aria-disabled="false" name="wpplus-' . $instance_key . '" value="" class="oo-ui-inputWidget-input oo-ui-buttonElement-button"><span class="oo-ui-iconElement-icon oo-ui-image-invert"></span><span class="oo-ui-labelElement-label">+</span><span class="oo-ui-indicatorElement-indicator oo-ui-image-invert"></span></button></span><div class="oo-ui-fieldLayout-body"><span class="oo-ui-fieldLayout-header"><label class="oo-ui-labelElement-label"></label></span><span class="oo-ui-fieldLayout-field"></span></div></div>', $instance_key );
				}
			}
		}
		$this->snakData = [];
		$htmlForm->setSubmitCallback(
			function( $formData ) use ( $form, $hasPlus, $htmlForm, $subPage ) {
				if ( !$hasPlus ) {
					return $this->trySubmit( $formData, $form, $htmlForm, $subPage );
				}
			}
		);
		$htmlForm->show();
	}

	private function getSerialized( $value, FormSnak $snak_definition, $field_key ) {
		if ( $value === null ) {
			return null;
		}
		try {
			return json_encode( $this->snakSerializer->serialize(
				$this->getSnakCached(
					$field_key,
					$snak_definition->getPropertyId(),
					$value
				)
			)["datavalue"] );
		} catch ( ParseException $e ) {
			return null;
		}
	}

	private function validateField( $value, FormSnak $snak_definition, $field_key ) {
		if ( $value === null || $value === '' ) {
			return true;
		}
		try {
			$propertyId = $snak_definition->getPropertyId();
			$snak = $this->getSnakCached( $field_key, $propertyId, $value );

			$dataTypeName = $this->propertyDataTypeLookup->getDataTypeIdForProperty( $propertyId );
			$validator = new CompositeValidator( $this->dataTypeValidatorFactory->getValidators( $dataTypeName ), true );
			$validatorResult = $validator->validate( $snak->getDataValue() );
			if ( !$validatorResult->isValid() ) {
				return $validatorResult->getErrors()[0]->getText();
			}
			return true;
		} catch ( ParseException $e ) {
			return $e->getMessage();
		}
	}

	private function getSnakCached( string $field_key, PropertyId $propertyId, string $value ) {
		if ( !isset( $this->snakData[ $field_key ] ) ) {
			$this->snakData[ $field_key ] = $this->getSnak(
				$propertyId,
				$value
			);
		}
		return $this->snakData[ $field_key ];
	}

	private function getFieldForSnak( FormSnak $snak ) {
		$validValues = $snak->getValidValues();
		$dropdown = count( $validValues ) > 0;
		$propertyId = $snak->getPropertyId();
		$propertyIdString = $propertyId->getSerialization();
		$dataTypeName = $this->propertyDataTypeLookup->getDataTypeIdForProperty( $propertyId );
		return [
			'label' => $this->getEntityLabel( $propertyId ),
			'class' => $dropdown ? 'HTMLSelectField' : 'HTMLTextField',
			'options' => [ null => "" ] + array_combine(
				array_map( [ $this, 'getEntityLabel' ], $validValues ),
				array_map( function( EntityId $entityId ) {
					return $entityId->getSerialization();
				}, $validValues )
			),
			'cssclass' => "wb-forms-valueview wb-forms-property-$propertyIdString wb-forms-datatype-$dataTypeName"
		];
	}

	private function getEntityLabel( EntityId $entityId ) {
		$label = $this->labelLookup->getLabel( $entityId );
		return $label === null ? $entityId->getSerialization() : $label->getText();
	}

	private function handlePlus( $instances ) {
		$pos = array_search( "plus", $instances );
		if ( $pos !== false ) {
			array_splice( $instances, $pos, 1 );
			array_splice( $instances, $pos + 1, 0, count( $instances ) );
		}
		return $instances;
	}

	private function getSnakFromFormData( $value, $fieldId, $formData, FormSnak $snak_definition ) {
		if ( $value === null && isset( $formData[ "$fieldId-hidden" ] ) && $formData[ "$fieldId-hidden" ] !== "" ) {
			$propertyId = $snak_definition->getPropertyId();
			$dataValue = $this->dataValueFactory->newFromArray( json_decode( $formData[ "$fieldId-hidden" ], true ) );
			return new PropertyValueSnak( $propertyId, $dataValue );
		} elseif ( $value === "" ) {
			return null;
		} else {
			return $this->snakData[ $fieldId ];
		}
	}

	public function trySubmit( $formData, Form $form, $htmlForm, $formName ) {
		$posted = [];
		foreach ( $formData as $idx => $val ) {
			if ( preg_match( "/^(\d+)_(\d+)-([^_-]+(_\d+)?)$/", $idx, $matches ) ) {
				$posted[$matches[1]][$matches[2]][$matches[3]] = $val;
			}
		}
		$item = $this->entityFactory->newEmpty( 'item' );
		$this->entityStore->assignFreshId( $item );
		$statements = [];
		foreach ( $form->getSections() as $idx => $section ) {
			if ( !isset( $posted[$idx] ) ) {
				continue;
			}
			foreach ( $posted[$idx] as $instance_id => $instance ) {
				if ( $section instanceof StatementSection ) {
					$snak = $this->getSnakFromFormData(
						$instance["main"],
						"{$idx}_{$instance_id}-main",
						$formData,
						$section->getMain()
					);
					if ( $snak ) {
						$statement = new Statement( $snak, null, null, $this->guidGenerator->newGuid( $item->getId() ) );
						$qualifiers = [];
						foreach ( $instance as $field_idx => $value ) {
							if ( $field_idx !== "main" ) {
								$field_id = explode( "_", $field_idx )[0];
								$snak = $this->getSnakFromFormData(
									$value,
									"{$idx}_{$instance_id}-$field_idx",
									$formData,
									$section->getQualifiers()[$field_id]->getSnak()
								);
								if ( $snak ) {
									$qualifiers[] = $snak;
								}
							}
						}
						$statement->setQualifiers( new SnakList( $qualifiers ) );
						$statements[] = $statement;
					}
				} else {
					foreach ( $instance as $field_idx => $value ) {
						$field_id = explode( "_", $field_idx )[0];
						$snak = $this->getSnakFromFormData(
							$value,
							"{$idx}_{$instance_id}-$field_idx",
							$formData,
							$section->getStatements()[$field_id]->getSnak()
						);
						if ( $snak ) {
							$statement = new Statement( $snak, null, null, $this->guidGenerator->newGuid( $item->getId() ) );
							$statements[] = $statement;
						}
					}
				}
			}
		}

		$item->setStatements( new StatementList( ...$statements ) );

		$editEntity = $this->editEntityFactory->newEditEntity( $this->getContext(), null, 0, true );

		$saveStatus = $editEntity->attemptSave(
			$item,
			$this->msg( "wikibase-forms-summary", $formName )->text(),
			EDIT_NEW,
			$htmlForm->getRequest()->getVal( 'wpEditToken' )
		);

		if ( !$saveStatus->isGood() ) {
			return $saveStatus;
		}

		$title = $this->getEntityTitle( $item->getId() );
		$entityUrl = $title->getFullURL();
		$this->getOutput()->redirect( $entityUrl );

		return Status::newGood( $item );
	}

	private function getEntityTitle( EntityId $id ) {
		return $this->entityTitleLookup->getTitleForId( $id );
	}

	private function getSnak( PropertyId $propertyId, string $value ): PropertyValueSnak {
		$dataTypeName = $this->propertyDataTypeLookup->getDataTypeIdForProperty( $propertyId );
		$parserOptions = new ParserOptions();
		$parserOptions->setOption( ValueParser::OPT_LANG, $this->getLanguage()->getCode() );
		$parser = $this->valueParserFactory->newParser( $dataTypeName, $parserOptions );

		// Can throw
		$parseResult = $parser->parse( $value );

		return new PropertyValueSnak( $propertyId, $parseResult );
	}

}

class MyHTMLForm extends OOUIHTMLForm {
	private $mLegend = [];

	// Hack for using section labels as legend instead of messages
	public function getLegend( $key ) {
		return isset( $this->mLegend[$key] ) ? $this->mLegend[$key] : '';
	}

	public function setLegend( $v, $key ) {
		$this->mLegend[$key] = $v;
	}

}

class ParsedHiddenField extends HTMLHiddenField {

	public function loadDataFromRequest( $request ) {
		$textFieldName = substr( $this->mName, 0, -7 );
		if ( $request->getCheck( $textFieldName ) ) {
			return $this->mParams["parse"]( $request->getText( $textFieldName ) );
		} else {
			return parent::loadDataFromRequest( $request );
		}
	}

}
