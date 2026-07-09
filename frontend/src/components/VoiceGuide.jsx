import { Languages, Mic, Square, Volume2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { ACCESS_LANGUAGES, phrase, resolveVoiceCommand, speakText, startSpeechRecognition } from '../utils/multilingualSpeech.js';

export default function VoiceGuide({ dashboardPath = '/search', onLanguageChange }) {
  const [language, setLanguage] = useState(() => localStorage.getItem('mdl_voice_language') || 'en');
  const [status, setStatus] = useState('idle');
  const [message, setMessage] = useState('');
  const recognitionRef = useRef(null);
  const navigate = useNavigate();

  useEffect(() => {
    localStorage.setItem('mdl_voice_language', language);
    onLanguageChange?.(language);
  }, [language, onLanguageChange]);

  useEffect(() => () => {
    recognitionRef.current?.abort?.();
    window.speechSynthesis?.cancel?.();
  }, []);

  function say(textKey) {
    try {
      speakText(phrase(language, textKey), language);
      setMessage(phrase(language, textKey));
    } catch (error) {
      setMessage(error.message);
    }
  }

  function stopListening() {
    recognitionRef.current?.stop?.();
    recognitionRef.current = null;
    setStatus('idle');
  }

  function handleTranscript(transcript) {
    const command = resolveVoiceCommand(transcript, dashboardPath);
    if (command?.path) {
      say(command.key);
      navigate(command.path);
      return;
    }
    setMessage(`${phrase(language, 'unknown')} ${transcript}`);
    navigate(transcript ? `/search?q=${encodeURIComponent(transcript)}` : '/search');
  }

  function listen() {
    try {
      recognitionRef.current = startSpeechRecognition(language, {
        onStart: () => {
          setStatus('listening');
          setMessage(phrase(language, 'listening'));
          speakText(phrase(language, 'listening'), language, { rate: 0.95 });
        },
        onResult: (transcript) => handleTranscript(transcript),
        onError: (event) => {
          setStatus('idle');
          setMessage(event.error === 'no-speech' ? phrase(language, 'noSpeech') : event.error || phrase(language, 'unsupportedRecognition'));
        },
        onEnd: () => {
          recognitionRef.current = null;
          setStatus('idle');
        },
      });
    } catch (error) {
      setMessage(error.message);
    }
  }

  return (
    <div className="voice-guide" aria-label="Multilingual voice guide">
      <label className="voice-guide-language" title="Voice language">
        <Languages size={15} />
        <select value={language} onChange={(event) => setLanguage(event.target.value)} aria-label="Voice guide language">
          {ACCESS_LANGUAGES.map((item) => <option key={item.code} value={item.code}>{item.label}</option>)}
        </select>
      </label>
      <button type="button" className="voice-guide-button" onClick={() => say('guideReady')} aria-label="Read voice guidance" title="Read voice guidance">
        <Volume2 size={17} />
      </button>
      <button
        type="button"
        className={`voice-guide-button ${status === 'listening' ? 'is-recording' : ''}`}
        onClick={status === 'listening' ? stopListening : listen}
        aria-label={status === 'listening' ? 'Stop voice command' : 'Start voice command'}
        title="Start voice command"
      >
        {status === 'listening' ? <Square size={15} /> : <Mic size={17} />}
      </button>
      {message && <span className="voice-guide-status" role="status">{message}</span>}
    </div>
  );
}