import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from "recharts";

export default function FuelCostChart({ data }) {
  return (
    <section className="card chart-card" aria-labelledby="chart-title">
      <div className="card-heading"><h2 id="chart-title">Coût mensuel du carburant (MAD)</h2></div>
      <div className="chart-wrap">
        <ResponsiveContainer width="100%" height="100%">
          <BarChart data={data} margin={{ top: 24, right: 8, left: -18, bottom: 0 }}>
            <CartesianGrid stroke="#edf1ef" vertical={false} />
            <XAxis dataKey="label" axisLine={false} tickLine={false} tick={{ fill: "#6b7874", fontSize: 12 }} />
            <YAxis axisLine={false} tickLine={false} tick={{ fill: "#8a9692", fontSize: 11 }} />
            <Tooltip cursor={{ fill: "#f4f8f7" }} formatter={(value) => `${new Intl.NumberFormat("fr-MA").format(value)} MAD`} />
            <Bar dataKey="total" fill="#79c7b6" radius={[7, 7, 0, 0]} label={{ position: "top", fill: "#56645f", fontSize: 11, formatter: (v) => new Intl.NumberFormat("fr-MA").format(v) }} />
          </BarChart>
        </ResponsiveContainer>
      </div>
    </section>
  );
}
