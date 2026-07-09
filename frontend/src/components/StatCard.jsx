export default function StatCard({ label, value, meta, icon: Icon, tone = 'neutral' }) {
  return (
    <div className={`stat-card stat-${tone}`}>
      <div className="stat-icon">{Icon && <Icon size={20} />}</div>
      <div>
        <p>{label}</p>
        <strong>{value}</strong>
        {meta && <span>{meta}</span>}
      </div>
    </div>
  );
}
