import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';

export default class SolidBrandIconComponent extends Component {
    @tracked width = 19;
    @tracked height = 19;
    constructor(owner, { options }) {
        super(...arguments);
        const { width = 19, height = 19 } = options || {};
        this.width = width;
        this.height = height;
    }
}
