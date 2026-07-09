import { Bell, LogOut, Menu, Mic, Search, Square, UserRound, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { NavLink, Outlet, useNavigate } from 'react-router-dom';
import * as voiceApi from '../api/voiceSearch.js';
import VoiceGuide from '../components/VoiceGuide.jsx';
import { getLanguage } from '../utils/multilingualSpeech.js';
import { useAuth } from '../context/AuthContext.jsx';
import { menus } from '../routes/menu.js';
import { roleLabels } from '../utils/roles.js';

export default function AppLayout() {
  const [open, setOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');
  const [voiceStatus, setVoiceStatus] = useState('idle');
  const [voiceMessage, setVoiceMessage] = useState('');
  const [voiceLanguage, setVoiceLanguage] = useState(() => localStorage.getItem('mdl_voice_language') || 'en');
  const recorderRef = useRef(null);
  const recognitionRef = useRef(null);
  const chunksRef = useRef([]);
  const timeoutRef = useRef(null);
  const { user, logout, dashboardPath } = useAuth();
  const navigate = useNavigate();
  const roleMenu = menus[user?.role] || [];

  async function handleLogout() {
    await logout();
    navigate('/login');
  }

  function handleSearch(event) {
    event.preventDefault();
    const query = searchQuery.trim();
    navigate(query ? `/search?q=${encodeURIComponent(query)}` : '/search');
  }

  function openTranscript(transcript) {
    const query = String(transcript || '').trim();
    if (!query) {
      setVoiceMessage('No speech was recognized. Please try again.');
      return;
    }
    setSearchQuery(query);
    setVoiceMessage(`Searching for "${query}"`);
    navigate(`/search?q=${encodeURIComponent(query)}`);
  }

  async function submitTranscript(transcript) {
    const payload = new FormData();
    payload.append('transcript', transcript);
    payload.append('language', voiceLanguage);
    try {
      const response = await voiceApi.sendVoiceSearch(payload);
      openTranscript(response.data?.transcript || transcript);
    } catch (error) {
      setVoiceMessage(error.message);
    } finally {
      setVoiceStatus('idle');
    }
  }

  async function submitRecording(audio) {
    setVoiceStatus('processing');
    const payload = new FormData();
    payload.append('audio', audio, 'global-search.webm');
    payload.append('language', voiceLanguage);
    try {
      const response = await voiceApi.sendVoiceSearch(payload);
      openTranscript(response.data?.transcript);
    } catch (error) {
      setVoiceMessage(error.message);
    } finally {
      setVoiceStatus('idle');
    }
  }

  function startBrowserRecognition() {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) return false;
    const recognition = new SpeechRecognition();
    recognitionRef.current = recognition;
    recognition.lang = getLanguage(voiceLanguage).speechCode;
    recognition.continuous = false;
    recognition.interimResults = false;
    recognition.maxAlternatives = 1;
    recognition.onstart = () => {
      setVoiceStatus('recording');
      setVoiceMessage('Listening...');
    };
    recognition.onresult = (event) => {
      setVoiceStatus('processing');
      submitTranscript(event.results?.[0]?.[0]?.transcript || '');
    };
    recognition.onerror = (event) => {
      setVoiceStatus('idle');
      setVoiceMessage(event.error === 'not-allowed'
        ? 'Microphone permission was denied.'
        : event.error === 'no-speech' ? 'No speech was detected.' : `Speech recognition failed: ${event.error}`);
    };
    recognition.onend = () => {
      recognitionRef.current = null;
      setVoiceStatus((current) => current === 'recording' ? 'idle' : current);
    };
    recognition.start();
    return true;
  }

  async function startMicrophone() {
    setVoiceMessage('');
    if (startBrowserRecognition()) return;
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
      setVoiceMessage('This browser cannot record audio. Type your search instead.');
      return;
    }
    try {
      const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      chunksRef.current = [];
      const recorder = new MediaRecorder(stream);
      recorderRef.current = recorder;
      recorder.ondataavailable = (event) => event.data.size > 0 && chunksRef.current.push(event.data);
      recorder.onstop = () => {
        clearTimeout(timeoutRef.current);
        stream.getTracks().forEach((track) => track.stop());
        const audio = new Blob(chunksRef.current, { type: recorder.mimeType || 'audio/webm' });
        if (audio.size) submitRecording(audio);
        else {
          setVoiceStatus('idle');
          setVoiceMessage('No audio was captured.');
        }
      };
      recorder.start();
      setVoiceStatus('recording');
      setVoiceMessage('Listening...');
      timeoutRef.current = setTimeout(() => recorder.state === 'recording' && recorder.stop(), 5000);
    } catch (error) {
      setVoiceStatus('idle');
      setVoiceMessage(error.name === 'NotAllowedError' ? 'Microphone permission was denied.' : error.message);
    }
  }

  function stopMicrophone() {
    recognitionRef.current?.stop();
    recognitionRef.current = null;
    if (recorderRef.current?.state === 'recording') recorderRef.current.stop();
  }

  useEffect(() => () => {
    clearTimeout(timeoutRef.current);
    recognitionRef.current?.abort();
    const recorder = recorderRef.current;
    if (recorder?.state === 'recording') recorder.stop();
    recorder?.stream?.getTracks().forEach((track) => track.stop());
  }, []);

  return (
    <div className="app-shell">
      <aside className={`sidebar ${open ? 'sidebar-open' : ''}`}>
        <div className="brand">
          <img className="brand-logo" src="/library-logo.svg" alt="Rwanda Library logo" />
          <div>
            <strong>MULTILINGUAL DIGITAL LIBRARY</strong>
            <span>Rwanda Knowledge Access</span>
          </div>
          <button className="mobile-close" onClick={() => setOpen(false)} aria-label="Close menu"><X size={18} /></button>
        </div>
        <nav className="side-nav">
          {roleMenu.map((item) => {
            const Icon = item.icon;
            return (
              <NavLink key={item.path} to={item.path} onClick={() => setOpen(false)}>
                <Icon size={18} />
                <span>{item.label}</span>
              </NavLink>
            );
          })}
        </nav>
      </aside>

      <div className="main-shell">
        <header className="topbar">
          <button className="menu-button" onClick={() => setOpen(true)} aria-label="Open menu"><Menu size={20} /></button>
          <form className="global-search" role="search" onSubmit={handleSearch}>
            <button className="global-search-submit" type="submit" aria-label="Search catalog"><Search size={18} /></button>
            <input
              aria-label="Search library books"
              placeholder="Search books, authors, topics..."
              value={searchQuery}
              onChange={(event) => setSearchQuery(event.target.value)}
            />
            <button
              className={`global-search-microphone ${voiceStatus === 'recording' ? 'is-recording' : ''}`}
              type="button"
              onClick={voiceStatus === 'recording' ? stopMicrophone : startMicrophone}
              disabled={voiceStatus === 'processing'}
              aria-label={voiceStatus === 'recording' ? 'Stop microphone search' : 'Search catalog using microphone'}
              title="Speak a book title, author, topic, faculty, or department"
            >
              {voiceStatus === 'recording' ? <Square size={16} /> : <Mic size={17} />}
            </button>
            {voiceMessage && <span className="global-search-voice-status" role="status">{voiceMessage}</span>}
          </form>
          <VoiceGuide dashboardPath={dashboardPath} onLanguageChange={setVoiceLanguage} />
          <NavLink to="/notifications" className="icon-link" aria-label="Notifications"><Bell size={19} /></NavLink>
          <NavLink to="/profile" className="user-chip">
            <UserRound size={18} />
            <span>{user?.name}</span>
            <small>{roleLabels[user?.role]}</small>
          </NavLink>
          <button className="icon-link" onClick={handleLogout} aria-label="Logout"><LogOut size={19} /></button>
        </header>
        <main className="content">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
