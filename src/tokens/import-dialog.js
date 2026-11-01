// Import of a token file. The file is read in the browser and sent as text; the server checks it like every write.
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button, Flex, Modal, TextControl } from '@wordpress/components';
import More from '../admin/more';
import { Findings, errorFindings } from './common';

// Same limit as Dtcg::MAX_BYTES; larger files are refused before they are sent.
const MAX_BYTES = 512 * 1024;

/**
 * @param {Object}               props
 * @param {() => void}           props.onClose    Closes the dialog.
 * @param {(id: number) => void} props.onImported Called with the ID of the new set.
 */
export default function ImportDialog( { onClose, onImported } ) {
	const [ file, setFile ] = useState( null );
	const [ title, setTitle ] = useState( '' );
	const [ findings, setFindings ] = useState( null );
	const [ isBusy, setIsBusy ] = useState( false );

	const choose = ( event ) => {
		const chosen = event.target.files?.[ 0 ] ?? null;
		setFile( chosen );
		setFindings( null );
		if ( chosen && ! title ) {
			setTitle( chosen.name.replace( /(\.tokens)?\.json$/i, '' ) );
		}
	};

	const submit = async ( event ) => {
		event.preventDefault();
		if ( file.size > MAX_BYTES ) {
			setFindings( [
				{
					message: __(
						'The token document is larger than 512 KB.',
						'leitvia-studio'
					),
				},
			] );
			return;
		}
		setIsBusy( true );
		try {
			const response = await apiFetch( {
				path: '/leitvia-studio/v1/token-sets/import',
				method: 'POST',
				data: { title, document: await file.text() },
			} );
			onImported( response.id );
		} catch ( error ) {
			setFindings( errorFindings( error ) );
		} finally {
			setIsBusy( false );
		}
	};

	return (
		<Modal
			title={ __( 'Import token file', 'leitvia-studio' ) }
			onRequestClose={ onClose }
			size="medium"
		>
			<form onSubmit={ submit }>
				<Flex
					direction="column"
					justify="flex-start"
					gap={ 4 }
					align="stretch"
				>
					{ findings && (
						<Findings
							heading={ __(
								'The file was not imported',
								'leitvia-studio'
							) }
							findings={ findings }
						/>
					) }
					<p>
						{ __(
							'A token file exported from Studio or a design tool, at most 512 KB; it is checked before it is saved.',
							'leitvia-studio'
						) }
					</p>
					<More>
						<p>
							{ __(
								'The file uses the DTCG format of design tokens and is checked against the contract of the active theme.',
								'leitvia-studio'
							) }
						</p>
					</More>
					<div>
						<label
							className="leitvia-studio-tokens__label"
							htmlFor="leitvia-studio-import-file"
						>
							{ __( 'Token file', 'leitvia-studio' ) }
						</label>
						<input
							id="leitvia-studio-import-file"
							type="file"
							accept=".json,application/json"
							onChange={ choose }
						/>
					</div>
					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ __( 'Title', 'leitvia-studio' ) }
						value={ title }
						onChange={ setTitle }
					/>
					<Flex justify="flex-end">
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							onClick={ onClose }
						>
							{ __( 'Cancel', 'leitvia-studio' ) }
						</Button>
						<Button
							__next40pxDefaultSize
							variant="primary"
							type="submit"
							isBusy={ isBusy }
							disabled={ ! file || isBusy }
							accessibleWhenDisabled
						>
							{ __( 'Import', 'leitvia-studio' ) }
						</Button>
					</Flex>
				</Flex>
			</form>
		</Modal>
	);
}
