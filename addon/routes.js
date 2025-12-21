import buildRoutes from 'ember-engines/routes';

export default buildRoutes(function () {
    this.route('home', { path: '/' });
    this.route('account');
    this.route('data', { path: '/data' }, function () {
        this.route('content', { path: '/:slug' });
    });
});
