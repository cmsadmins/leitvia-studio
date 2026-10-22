// Image field: an attachment ID chosen from the media library, previewed with the alt text of the attachment.
import { __, sprintf } from '@wordpress/i18n';
import { MediaUpload, MediaUploadCheck } from '@wordpress/block-editor';
import { Button, Flex } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useInstanceId } from '@wordpress/compose';
import { store as coreStore } from '@wordpress/core-data';
import { decodeEntities } from '@wordpress/html-entities';

// Text alternative of the preview: the alt text, else the title, else the file name.
const textOf = ( image ) =>
	image.alt_text ||
	decodeEntities( image.title?.raw || image.title?.rendered || '' ) ||
	( image.source_url ?? '' ).split( '/' ).pop();

export default function ImageField( { data, field, onChange } ) {
	const id = Number( field.getValue( { item: data } ) ) || 0;
	const labelId = useInstanceId( ImageField, 'leitvia-studio-image-label' );
	const image = useSelect(
		( select ) => ( id ? select( coreStore ).getMedia( id ) : null ),
		[ id ]
	);
	const selectLabel = sprintf(
		/* translators: %s: field label, for example Poster. */
		__( 'Select image for %s', 'leitvia-studio' ),
		field.label
	);
	const replaceLabel = sprintf(
		/* translators: %s: field label, for example Poster. */
		__( 'Replace %s', 'leitvia-studio' ),
		field.label
	);
	const set = ( value ) =>
		onChange( field.setValue( { item: data, value } ) );

	return (
		<div
			role="group"
			aria-labelledby={ labelId }
			className="leitvia-studio-image-field"
		>
			<p id={ labelId } className="leitvia-studio-image-field__label">
				{ field.label }
			</p>
			{ image && (
				<img
					className="leitvia-studio-image-field__preview"
					src={
						image.media_details?.sizes?.medium?.source_url ??
						image.source_url
					}
					alt={ textOf( image ) }
				/>
			) }
			<MediaUploadCheck
				fallback={
					<p>
						{ __(
							'You are not allowed to choose images.',
							'leitvia-studio'
						) }
					</p>
				}
			>
				<Flex justify="flex-start" wrap>
					<MediaUpload
						allowedTypes={ [ 'image' ] }
						value={ id }
						onSelect={ ( media ) => set( media.id ) }
						render={ ( { open } ) => (
							<Button
								__next40pxDefaultSize
								variant="secondary"
								onClick={ open }
								aria-label={ id ? replaceLabel : selectLabel }
							>
								{ id
									? __( 'Replace', 'leitvia-studio' )
									: __( 'Select image', 'leitvia-studio' ) }
							</Button>
						) }
					/>
					{ id > 0 && (
						<Button
							__next40pxDefaultSize
							variant="tertiary"
							isDestructive
							onClick={ () => set( 0 ) }
							aria-label={ sprintf(
								/* translators: %s: field label, for example Poster. */
								__( 'Remove %s', 'leitvia-studio' ),
								field.label
							) }
						>
							{ __( 'Remove', 'leitvia-studio' ) }
						</Button>
					) }
				</Flex>
			</MediaUploadCheck>
		</div>
	);
}
