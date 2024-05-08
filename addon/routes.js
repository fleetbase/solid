import buildRoutes from 'ember-engines/routes';

export default buildRoutes(function () {
    this.route('home', { path: '/' });
    this.route('account');
    this.route('pods', function () {
        this.route('explorer', { path: '/explorer/:id' }, function () {
            this.route('content', { path: '/~/:slug' });
        });
        this.route('index', { path: '/' }, function () {
            this.route('pod', { path: '/pod/:slug' });
        });
    });
});
