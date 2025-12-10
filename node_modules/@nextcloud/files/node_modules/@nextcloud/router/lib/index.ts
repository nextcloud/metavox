/**
 * Get an url with webroot to a file in an app
 *
 * @param {string} app the id of the app the file belongs to
 * @param {string} file the file path relative to the app folder
 * @return {string} URL with webroot to a file
 */
export const linkTo = (app: string, file: string) => generateFilePath(app, '', file)

/**
 * Creates a relative url for remote use
 *
 * @param {string} service id
 * @return {string} the url
 */
const linkToRemoteBase = (service: string) => getRootUrl() + '/remote.php/' + service

/**
 * @brief Creates an absolute url for remote use
 * @param {string} service id
 * @return {string} the url
 */
export const generateRemoteUrl = (service: string) => window.location.protocol + '//' + window.location.host + linkToRemoteBase(service)

/**
 * Get the base path for the given OCS API service
 *
 * @param {string} url OCS API service url
 * @param {object} params parameters to be replaced into the service url
 * @param {UrlOptions} options options for the parameter replacement
 * @param {boolean} options.escape Set to false if parameters should not be URL encoded (default true)
 * @param {Number} options.ocsVersion OCS version to use (defaults to 2)
 * @return {string} Absolute path for the OCS URL
 */
export const generateOcsUrl = (url: string, params?: object, options?: UrlOptions) => {
    const allOptions = Object.assign({
        ocsVersion: 2
    }, options || {})

   const version = (allOptions.ocsVersion === 1) ? 1 : 2

    return window.location.protocol + '//' + window.location.host + getRootUrl() + '/ocs/v' + version + '.php' + _generateUrlPath(url, params, options);
}

export interface UrlOptions {
    escape: boolean,
    noRewrite: boolean,
    ocsVersion: Number
}

/**
 * Generate a url path, which can contain parameters
 *
 * Parameters will be URL encoded automatically
 *
 * @param {string} url address (can contain placeholders e.g. /call/{token} would replace {token} with the value of params.token
 * @param {object} params parameters to be replaced into the address
 * @param {UrlOptions} options options for the parameter replacement
 * @return {string} Path part for the given URL
 */
const _generateUrlPath = (url: string, params?: object, options?: UrlOptions) => {
    const allOptions = Object.assign({
        escape: true
    }, options || {})

    const _build = function (text: string, vars: object) {
        vars = vars || {};
        return text.replace(/{([^{}]*)}/g,
            function (a: string, b: any) {
                var r = vars[b];
                if (allOptions.escape) {
                    return (typeof r === 'string' || typeof r === 'number') ? encodeURIComponent(r.toString()) : encodeURIComponent(a);
                } else {
                    return (typeof r === 'string' || typeof r === 'number') ? r.toString() : a;
                }
            }
        );
    };

    if (url.charAt(0) !== '/') {
        url = '/' + url;
    }

    return _build(url, params || {});
}

/**
 * Generate the url with webroot for the given relative url, which can contain parameters
 *
 * Parameters will be URL encoded automatically
 *
 * @param {string} url address (can contain placeholders e.g. /call/{token} would replace {token} with the value of params.token
 * @param {object} params parameters to be replaced into the url
 * @param {UrlOptions} options options for the parameter replacement
 * @param {boolean} options.noRewrite True if you want to force index.php being added
 * @param {boolean} options.escape Set to false if parameters should not be URL encoded (default true)
 * @return {string} URL with webroot for the given relative URL
 */
export const generateUrl = (url: string, params?: object, options?: UrlOptions) => {
    const allOptions = Object.assign({
        noRewrite: false
    }, options || {})

    if (window?.OC?.config?.modRewriteWorking === true && !allOptions.noRewrite) {
        return getRootUrl() + _generateUrlPath(url, params, options);
    }

    return getRootUrl() + '/index.php' + _generateUrlPath(url, params, options);
}

/**
 * Get the path with webroot to an image file
 * if no extension is given for the image, it will automatically decide
 * between .png and .svg based on what the browser supports
 *
 * @param {string} app the app id to which the image belongs
 * @param {string} file the name of the image file
 * @return {string}
 */
export const imagePath = (app: string, file: string) => {
    if (file.indexOf('.') === -1) {
        //if no extension is given, use svg
        return generateFilePath(app, 'img', file + '.svg')
    }

    return generateFilePath(app, 'img', file)
}

/**
 * Get the url with webroot for a file in an app
 *
 * @param {string} app the id of the app
 * @param {string} type the type of the file to link to (e.g. css,img,ajax.template)
 * @param {string} file the filename
 * @return {string} URL with webroot for a file in an app
 */
export const generateFilePath = (app: string, type: string, file: string) => {
    const isCore = window?.OC?.coreApps?.indexOf(app) !== -1
    let link = getRootUrl()
    if (file.substring(file.length - 3) === 'php' && !isCore) {
        link += '/index.php/apps/' + app;
        if (file !== 'index.php') {
            link += '/'
            if (type) {
                link += encodeURI(type + '/')
            }
            link += file
        }
    } else if (file.substring(file.length - 3) !== 'php' && !isCore) {
        link = getAppRootUrl(app)
        if (type) {
            link += '/' + type + '/'
        }
        if (link.substring(link.length - 1) !== '/') {
            link += '/'
        }
        link += file
    } else {
        if ((app === 'settings' || app === 'core' || app === 'search') && type === 'ajax') {
            link += '/index.php/'
        } else {
            link += '/'
        }
        if (!isCore) {
            link += 'apps/'
        }
        if (app !== '') {
            app += '/'
            link += app
        }
        if (type) {
            link += type + '/'
        }
        link += file
    }
    return link
}

/**
 * Return the web root path where this Nextcloud instance
 * is accessible, with a leading slash.
 * For example "/nextcloud".
 *
 * @return {string} web root path
 */
export function getRootUrl(): string {
    let webroot = window._oc_webroot

    if (typeof webroot === 'undefined') {
        webroot = location.pathname
        const pos = webroot.indexOf('/index.php/')
        if (pos !== -1) {
            webroot = webroot.substr(0, pos)
        } else {
            webroot = webroot.substr(0, webroot.lastIndexOf('/'))
        }
    }
    return webroot
}

/**
 * Return the web root path for a given app
 * @param {string} app The ID of the app
 */
export function getAppRootUrl(app: string): string {
    const webroots = window._oc_appswebroots ?? {}
    return webroots[app] ?? ''
}
