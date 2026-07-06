// @flow
import React from 'react';
import {Icon} from 'sulu-admin-bundle/components';
import styles from './floatingButton.scss';

/**
 * Round floating action button used by the shared launcher. `skin` selects the
 * colour ("assistant" or "image"); positioning is owned by the launcher, not
 * this component.
 */
export default class FloatingButton extends React.PureComponent {
    render() {
        const {ariaLabel, icon, onClick, skin} = this.props;

        return (
            <button
                aria-label={ariaLabel}
                className={styles.fab + ' ' + (styles[skin] || '')}
                onClick={onClick}
                type="button"
            >
                <Icon name={icon} />
            </button>
        );
    }
}
