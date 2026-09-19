import { Button } from '@wordpress/components';
import { useCopyToClipboard } from '@wordpress/compose';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const CopyButton = ( {
	text,
	children,
	copiedLabel = __( 'Copied', 'edd-composer' ),
	...buttonProps
} ) => {
	const [ copied, setCopied ] = useState( false );
	const timeout = useRef();
	const onCopy = useCallback( () => {
		window.clearTimeout( timeout.current );
		setCopied( true );
		timeout.current = window.setTimeout( () => setCopied( false ), 2000 );
	}, [] );
	const copyRef = useCopyToClipboard( text, onCopy );

	useEffect(
		() => () => {
			window.clearTimeout( timeout.current );
		},
		[]
	);

	return (
		<Button { ...buttonProps } ref={ copyRef }>
			{ copied ? copiedLabel : children }
		</Button>
	);
};

export default CopyButton;
