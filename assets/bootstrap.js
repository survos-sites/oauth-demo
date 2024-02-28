import { startStimulusApp } from '@symfony/stimulus-bundle';
import Clipboard from 'stimulus-clipboard'

const app = startStimulusApp();
// register any custom, 3rd party controllers here
app.register('clipboard', Clipboard);
