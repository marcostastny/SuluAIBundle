// @flow
import React from 'react';
import {observer} from 'mobx-react';
import {userStore} from 'sulu-admin-bundle/stores';
import {translate} from 'sulu-admin-bundle/utils';
import FloatingButton from '../../components/FloatingButton';
import assistantContextStore from '../../stores/assistantContextStore';
import imageGeneratorStore from '../../stores/imageGeneratorStore';
import styles from './launcher.scss';

/**
 * Single fixed container that owns both floating buttons so they stack cleanly
 * and share positioning. Hidden entirely while either panel/overlay is open, so
 * a button can never paint over the assistant panel or leave a dead gap.
 */
@observer
class Launcher extends React.Component {
    handleOpenImage = () => {
        imageGeneratorStore.openOverlay({
            locale: userStore.contentLocale || 'en',
            collectionId: null,
        });
    };

    handleToggleAssistant = () => {
        assistantContextStore.togglePanel();
    };

    render() {
        if (assistantContextStore.panelOpen || imageGeneratorStore.open) {
            return null;
        }

        const showImage = imageGeneratorStore.available;
        const showAssistant = assistantContextStore.available;
        if (!showImage && !showAssistant) {
            return null;
        }

        return (
            <div className={styles.launcher}>
                {showImage &&
                    <FloatingButton
                        ariaLabel={translate('sulu_ai.image_generate')}
                        icon="su-image"
                        onClick={this.handleOpenImage}
                        skin="image"
                    />
                }
                {showAssistant &&
                    <FloatingButton
                        ariaLabel={translate('sulu_ai.assistant')}
                        icon="su-magic"
                        onClick={this.handleToggleAssistant}
                        skin="assistant"
                    />
                }
            </div>
        );
    }
}

export default Launcher;
