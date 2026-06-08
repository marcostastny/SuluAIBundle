// @flow
import React from 'react';
import {Input} from 'sulu-admin-bundle/components';

export default class PasswordField extends React.Component {
    render() {
        const {dataPath, disabled, error, onChange, onFinish, value} = this.props;

        return (
            <Input
                autocomplete="new-password"
                disabled={!!disabled}
                id={dataPath}
                onBlur={onFinish}
                onChange={onChange}
                type="password"
                valid={!error}
                value={value}
            />
        );
    }
}
