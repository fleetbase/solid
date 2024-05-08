import Controller from '@ember/controller';
import { action } from '@ember/object';

export default class PodsExplorerContentController extends Controller {
    @action setOverlayContext(overlayContextApi) {
        this.overlayContextApi = overlayContextApi;
    }

    @action onPressClose() {
        window.history.back();
    }
}
