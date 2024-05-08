import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action, computed } from '@ember/object';
import { isArray } from '@ember/array';

export default class TableCellPodContentActionsComponent extends Component {
    constructor(owner, { column, row }) {
        super(...arguments);
        if (isArray(column.actions)) {
            this.actions = column.actions;
        }

        if (typeof column.actions === 'function') {
            this.actions = column.actions(row);
        }
    }

    @tracked actions = [];
    @tracked defaultButtonText = 'Actions';

    @computed('args.column.ddButtonText', 'defaultButtonText') get buttonText() {
        const { ddButtonText } = this.args.column;

        if (ddButtonText === undefined) {
            return this.defaultButtonText;
        }

        if (ddButtonText === false) {
            return null;
        }

        return ddButtonText;
    }

    @action setupComponent(dropdownWrapperNode) {
        const tableCellNode = this.getOwnerTableCell(dropdownWrapperNode);
        tableCellNode.style.overflow = 'visible';
    }

    @action getOwnerTableCell(dropdownWrapperNode) {
        while (dropdownWrapperNode) {
            dropdownWrapperNode = dropdownWrapperNode.parentNode;

            if (dropdownWrapperNode.tagName.toLowerCase() === 'td') {
                return dropdownWrapperNode;
            }
        }

        return undefined;
    }

    @action onDropdownItemClick(columnAction, row, dd) {
        if (typeof dd?.actions?.close === 'function') {
            dd.actions.close();
        }

        if (typeof columnAction?.fn === 'function') {
            columnAction.fn(row);
        }
    }

    @action calculatePosition(trigger) {
        let { width } = trigger.getBoundingClientRect();

        let style = {
            marginTop: '0px',
            right: width + 3,
            top: 0,
        };

        return { style };
    }
}
