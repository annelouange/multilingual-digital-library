export default function DashboardBrand({ title, description }) {
  return (
    <section className="dashboard-brand-banner">
      <img src="/library-logo.svg" alt="Rwanda Library logo" />
      <div>
        <p className="eyebrow">Rwanda Library Network</p>
        <h2>{title}</h2>
        <p>{description}</p>
      </div>
    </section>
  );
}
