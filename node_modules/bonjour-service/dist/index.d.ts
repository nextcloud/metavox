import BonjourService from './lib/bonjour';
import * as imported from './lib/bonjour';
declare class Bonjour extends BonjourService {
}
import BonjourClass = Bonjour;
declare namespace Bonjour {
    export import Bonjour = BonjourClass;
    export import Service = imported.Service;
    export import Browser = imported.Browser;
    export import ServiceReferer = imported.ServiceReferer;
    export import ServiceConfig = imported.ServiceConfig;
    export import BrowserConfig = imported.BrowserConfig;
}
export = Bonjour;
