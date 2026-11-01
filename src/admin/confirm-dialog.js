// Confirmation of a step that is hard to undo: the question, Cancel and the button that does it. Inside the modal of a
// DataViews action (RenderModal) it renders without its own Modal.
import { __ } from '@wordpress/i18n';
import { Button, Flex, Modal } from '@wordpress/components';

/**
 * @param {Object}     props
 * @param {string}     props.title         Title of the modal.
 * @param {string}     props.confirmLabel  Label of the button that does the step.
 * @param {() => void} props.onConfirm     Does the step.
 * @param {() => void} props.onCancel      Closes the dialog.
 * @param {boolean}    props.isBusy        The step is running.
 * @param {boolean}    props.isDestructive The step deletes or revokes something.
 * @param {string}     props.size          Size of the modal.
 * @param {boolean}    props.modal         False inside a modal that DataViews opens.
 * @param {Object}     props.children      What the step changes.
 */
export default function ConfirmDialog( {
	title,
	confirmLabel,
	onConfirm,
	onCancel,
	isBusy = false,
	isDestructive = false,
	size = 'medium',
	modal = true,
	children,
} ) {
	const body = (
		<Flex direction="column" justify="flex-start" gap={ 4 } align="stretch">
			{ children }
			<Flex justify="flex-end">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onCancel }
				>
					{ __( 'Cancel', 'leitvia-studio' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isDestructive={ isDestructive }
					isBusy={ isBusy }
					disabled={ isBusy }
					accessibleWhenDisabled
					onClick={ onConfirm }
				>
					{ confirmLabel }
				</Button>
			</Flex>
		</Flex>
	);
	if ( ! modal ) {
		return body;
	}
	return (
		<Modal title={ title } onRequestClose={ onCancel } size={ size }>
			{ body }
		</Modal>
	);
}
