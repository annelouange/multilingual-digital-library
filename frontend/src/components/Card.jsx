export default function Card({ title, eyebrow, actions, children, className = '' }) {
  return (
    <section className={`card ${className}`}>
      {(title || eyebrow || actions) && (
        <header className="card-header">
          <div>
            {eyebrow && <p className="eyebrow">{eyebrow}</p>}
            {title && <h2>{title}</h2>}
          </div>
          {actions && <div className="card-actions">{actions}</div>}
        </header>
      )}
      {children}
    </section>
  );
}
