export const ACCESS_LANGUAGES = [
  { code: 'en', label: 'English', speechCode: 'en-US' },
  { code: 'fr', label: 'Francais', speechCode: 'fr-FR' },
];

const PHRASES = {
  en: {
    listening: 'Listening. Say a page name, search topic, or command.',
    noSpeech: 'No speech was recognized. Please try again.',
    unsupportedRecognition: 'Speech recognition is not available in this browser. You can still use the microphone search page.',
    unsupportedSpeech: 'Speech output is not available in this browser.',
    guideReady: 'Voice guide is ready. You can say search books, notifications, favorites, bookmarks, borrowed books, recommendations, dashboard, or profile.',
    search: 'Opening search.',
    notifications: 'Opening notifications.',
    favorites: 'Opening favorites.',
    bookmarks: 'Opening bookmarks.',
    borrowed: 'Opening borrowed books.',
    recommendations: 'Opening recommendations.',
    profile: 'Opening profile.',
    dashboard: 'Opening dashboard.',
    unknown: 'I did not understand that command. Opening search with your words.',
  },
  fr: {
    listening: 'J ecoute. Dites une page, un sujet de recherche ou une commande.',
    noSpeech: 'Aucune parole reconnue. Veuillez reessayer.',
    unsupportedRecognition: 'La reconnaissance vocale n est pas disponible dans ce navigateur. Vous pouvez utiliser la page de recherche vocale.',
    unsupportedSpeech: 'La sortie vocale n est pas disponible dans ce navigateur.',
    guideReady: 'Le guide vocal est pret. Vous pouvez dire recherche livres, notifications, favoris, signets, emprunts, recommandations, tableau de bord ou profil.',
    search: 'Ouverture de la recherche.',
    notifications: 'Ouverture des notifications.',
    favorites: 'Ouverture des favoris.',
    bookmarks: 'Ouverture des signets.',
    borrowed: 'Ouverture des livres empruntes.',
    recommendations: 'Ouverture des recommandations.',
    profile: 'Ouverture du profil.',
    dashboard: 'Ouverture du tableau de bord.',
    unknown: 'Je n ai pas compris cette commande. Je lance une recherche avec vos mots.',
  },
};

const COMMANDS = [
  { key: 'notifications', path: '/notifications', terms: ['notification', 'notifications'] },
  { key: 'favorites', path: '/student/favorites', terms: ['favorite', 'favorites', 'favourite', 'favoris'] },
  { key: 'bookmarks', path: '/student/bookmarks', terms: ['bookmark', 'bookmarks', 'signet', 'marque'] },
  { key: 'borrowed', path: '/student/borrowed', terms: ['borrowed', 'borrow', 'emprunt'] },
  { key: 'recommendations', path: '/recommendations', terms: ['recommendation', 'recommendations', 'recommandation'] },
  { key: 'profile', path: '/profile', terms: ['profile', 'profil'] },
  { key: 'dashboard', path: null, terms: ['dashboard', 'home', 'accueil'] },
  { key: 'search', path: '/search', terms: ['search', 'book', 'books', 'catalog', 'chercher', 'recherche', 'livre', 'livres'] },
];

export function getLanguage(code) {
  return ACCESS_LANGUAGES.find((item) => item.code === code) || ACCESS_LANGUAGES[0];
}

export function phrase(code, key) {
  return PHRASES[code]?.[key] || PHRASES.en[key] || key;
}

export function speakText(text, languageCode = 'en', options = {}) {
  if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) {
    throw new Error(phrase(languageCode, 'unsupportedSpeech'));
  }
  window.speechSynthesis.cancel();
  const utterance = new SpeechSynthesisUtterance(text);
  const lang = getLanguage(languageCode);
  utterance.lang = lang.speechCode;
  utterance.rate = options.rate || 0.92;
  utterance.pitch = options.pitch || 1;
  const voices = window.speechSynthesis.getVoices?.() || [];
  const exactVoice = voices.find((voice) => voice.lang?.toLowerCase() === lang.speechCode.toLowerCase());
  const familyVoice = voices.find((voice) => voice.lang?.toLowerCase().startsWith(languageCode.toLowerCase()));
  utterance.voice = exactVoice || familyVoice || null;
  window.speechSynthesis.speak(utterance);
  return utterance;
}

export function speakTextInChunks(text, languageCode = 'en', options = {}) {
  if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) {
    throw new Error(phrase(languageCode, 'unsupportedSpeech'));
  }
  window.speechSynthesis.cancel();
  const clean = String(text || '').replace(/\s+/g, ' ').trim();
  const chunks = [];
  let remaining = clean;
  while (remaining) {
    const slice = remaining.slice(0, options.chunkSize || 700);
    const boundary = Math.max(slice.lastIndexOf('. '), slice.lastIndexOf('? '), slice.lastIndexOf('! '), slice.lastIndexOf('; '), slice.lastIndexOf(', '));
    const chunk = (boundary > 220 ? slice.slice(0, boundary + 1) : slice).trim();
    chunks.push(chunk);
    remaining = remaining.slice(chunk.length).trim();
  }

  const lang = getLanguage(languageCode);
  const voices = window.speechSynthesis.getVoices?.() || [];
  const exactVoice = voices.find((voice) => voice.lang?.toLowerCase() === lang.speechCode.toLowerCase());
  const familyVoice = voices.find((voice) => voice.lang?.toLowerCase().startsWith(languageCode.toLowerCase()));
  let index = 0;
  const controller = {
    cancelled: false,
    pause: () => window.speechSynthesis.pause(),
    resume: () => window.speechSynthesis.resume(),
    cancel: () => {
      controller.cancelled = true;
      window.speechSynthesis.cancel();
    },
    onend: null,
    onerror: null,
  };

  const speakNext = () => {
    if (controller.cancelled) return;
    if (index >= chunks.length) {
      controller.onend?.();
      return;
    }
    const utterance = new SpeechSynthesisUtterance(chunks[index]);
    utterance.lang = lang.speechCode;
    utterance.rate = options.rate || 0.92;
    utterance.pitch = options.pitch || 1;
    utterance.voice = exactVoice || familyVoice || null;
    utterance.onend = () => {
      index += 1;
      speakNext();
    };
    utterance.onerror = (event) => controller.onerror?.(event);
    window.speechSynthesis.speak(utterance);
  };

  speakNext();
  return controller;
}

export function startSpeechRecognition(languageCode, handlers = {}) {
  const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
  if (!SpeechRecognition) {
    throw new Error(phrase(languageCode, 'unsupportedRecognition'));
  }
  const recognition = new SpeechRecognition();
  recognition.lang = getLanguage(languageCode).speechCode;
  recognition.continuous = false;
  recognition.interimResults = false;
  recognition.maxAlternatives = 3;
  recognition.onstart = () => handlers.onStart?.();
  recognition.onresult = (event) => {
    const alternatives = Array.from(event.results?.[0] || []).map((item) => item.transcript).filter(Boolean);
    handlers.onResult?.(alternatives[0] || '', alternatives);
  };
  recognition.onerror = (event) => handlers.onError?.(event);
  recognition.onend = () => handlers.onEnd?.();
  recognition.start();
  return recognition;
}

export function resolveVoiceCommand(transcript, dashboardPath = '/search') {
  const clean = String(transcript || '').toLowerCase().trim();
  if (!clean) return null;
  const command = COMMANDS.find((item) => item.terms.some((term) => clean.includes(term)));
  if (!command) return null;
  return {
    ...command,
    path: command.key === 'dashboard' ? dashboardPath : command.path,
  };
}
