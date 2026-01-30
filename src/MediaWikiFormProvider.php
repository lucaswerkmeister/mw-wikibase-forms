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

use ContentHandler;
use Exception;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use Title;
use Wikibase\DataModel\Entity\EntityIdParser;
use WikibaseForms\FormParser;
use WikibaseForms\Model\Form;
use WikiPage;

class MediaWikiFormProvider implements FormProvider {

	public function __construct( EntityIdParser $entityIdParser ) {
		$this->entityIdParser = $entityIdParser;
	}

	public function getForm( string $name ): Form {
		$services = MediaWikiServices::getInstance();
		$config = $services->getConfigFactory()->makeConfig( 'wikibase-forms' );
		$ns = $config->get( 'WikibaseFormsNamespace' );
		$title = Title::makeTitleSafe( $ns, $name );
		if ( !$title || !$title->exists() ) {
			throw new Exception( "Form not found" );
		}
		$wikiPage = $services->getWikiPageFactory()->newFromTitle( $title );
		$revision = $wikiPage->getRevisionRecord();
		$content = $revision->getContent( SlotRecord::MAIN, RevisionRecord::RAW );
		$text = ContentHandler::getContentText( $content );
		$formParser = new FormParser( $this->entityIdParser );
		$form = $formParser->parse( $text );
		return $form;
	}

}
